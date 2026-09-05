<?php

namespace Tests {
    function previewAssert(bool $condition, string $message): void
    {
        if (! $condition) {
            fwrite(STDERR, $message."\n");
            exit(1);
        }
    }

    function runIssue55ReplayPreview(string $directory): string
    {
        $command = escapeshellarg(PHP_BINARY)
            .' '.escapeshellarg(__DIR__.'/issue55_offline_replay_test.php')
            .' --preview-dir='.escapeshellarg($directory)
            .' 2>&1';
        exec($command, $lines, $status);
        previewAssert($status === 0, 'The offline replay preview command must succeed: '.implode("\n", $lines));

        return implode("\n", $lines);
    }

    function removeIssue55PreviewDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                unlink($directory.'/'.$entry);
            }
        }
        rmdir($directory);
    }

    $baseDirectory = sys_get_temp_dir().'/issue55-preview-artifact-test';
    $firstDirectory = $baseDirectory.'-first';
    $secondDirectory = $baseDirectory.'-second';
    removeIssue55PreviewDirectory($firstDirectory);
    removeIssue55PreviewDirectory($secondDirectory);
    mkdir($firstDirectory, 0777, true);
    mkdir($secondDirectory, 0777, true);

    runIssue55ReplayPreview($firstDirectory);
    runIssue55ReplayPreview($secondDirectory);

    $firstManifest = $firstDirectory.'/manifest.json';
    $secondManifest = $secondDirectory.'/manifest.json';
    $firstGallery = $firstDirectory.'/gallery.html';
    $secondGallery = $secondDirectory.'/gallery.html';
    previewAssert(is_file($firstManifest) && is_file($firstGallery), 'The replay must create a manifest and static gallery.');
    previewAssert(['gallery.html', 'manifest.json'] === array_values(array_filter(scandir($firstDirectory) ?: [], static fn (string $entry): bool => $entry !== '.' && $entry !== '..')), 'The preview run must write only its explicit artifact files.');
    previewAssert(file_get_contents($firstManifest) === file_get_contents($secondManifest), 'The preview manifest must be reproducible.');
    previewAssert(file_get_contents($firstGallery) === file_get_contents($secondGallery), 'The preview gallery must be reproducible.');

    $manifest = json_decode((string) file_get_contents($firstManifest), true, 512, JSON_THROW_ON_ERROR);
    previewAssert(is_array($manifest['cases'] ?? null) && count($manifest['cases']) === 12, 'The preview manifest must contain the exact bounded replay case set.');
    $casesById = array_column($manifest['cases'], null, 'id');
    foreach ([
        'ambiguous' => 'ambiguous_identity',
        'confusable' => 'mixed_script_identity_rejected',
        'unsupported_locale' => 'unsupported_language_tag',
        'malformed_locale' => 'malformed_language_tag',
        'missing_translation' => 'explicit_alias_unavailable',
        'incompatible_description' => 'language_incompatible_description',
    ] as $caseId => $reason) {
        previewAssert(($casesById[$caseId]['applicability']['reason'] ?? null) === $reason, $caseId.' must expose its deterministic safe rejection reason.');
    }
    foreach ($manifest['cases'] as $case) {
        previewAssert(mb_strlen((string) ($case['evidence']['title'] ?? '')) <= 160, 'Raw fixture display titles must remain bounded.');
        previewAssert(! array_key_exists('description', $case['evidence'] ?? []), 'Replay evidence must never include raw descriptions.');
    }
    previewAssert(['source' => 'original'] === ($casesById['catalogue']['selected_title_provenance'] ?? null), 'The preview must expose privacy-safe original-title provenance for an accepted identity.');
    previewAssert(str_contains((string) file_get_contents($firstGallery), '&lt;script&gt;'), 'The gallery must HTML-escape fixture evidence.');
    $serialized = (string) file_get_contents($firstManifest).(string) file_get_contents($firstGallery);
    previewAssert(! str_contains($serialized, 'provider.invalid') && ! str_contains($serialized, 'https://') && ! str_contains($serialized, 'private fixture description'), 'The preview artifact must redact provider hosts, URLs, and descriptions.');

    $goldenPath = $baseDirectory.'-mismatch.json';
    file_put_contents($goldenPath, "{}\n");
    $mismatchCommand = escapeshellarg(PHP_BINARY)
        .' '.escapeshellarg(__DIR__.'/issue55_offline_replay_test.php')
        .' --golden='.escapeshellarg($goldenPath)
        .' 2>&1';
    exec($mismatchCommand, $mismatchLines, $mismatchStatus);
    previewAssert($mismatchStatus !== 0 && str_contains(implode("\n", $mismatchLines), 'Offline replay golden mismatch.'), 'A replay golden mismatch must return nonzero.');
    unlink($goldenPath);

    removeIssue55PreviewDirectory($firstDirectory);
    removeIssue55PreviewDirectory($secondDirectory);
    fwrite(STDOUT, "Issue55 preview artifact tests passed.\n");
}
