<?php

require __DIR__.'/HostEnrichmentApiTest.php';

function assertCancellationSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
        exit(1);
    }
}

[$cancelledResult, $cancelledHost, $cancelledContext] = \Tests\runFixture(['applied'], [], 1);
assertCancellationSame('cancelled', $cancelledResult->status, 'Cancellation before the host boundary must remain visible to callers.');
assertCancellationSame(false, $cancelledResult->success, 'Cancellation must not be reported as enrichment success.');
assertCancellationSame(0, count($cancelledHost->applies), 'Cancellation must not conditionally apply a partially enriched snapshot.');
assertCancellationSame(0, $cancelledResult->data['programmes_updated'] ?? 0, 'Cancelled work must not count unpersisted programme updates.');
assertCancellationSame(['Processing canonical host EPG snapshot.'], $cancelledContext->messages, 'Cancellation must occur before any published-batch heartbeat.');

foreach ([
    'legacy_cache_read_only' => ['legacy_cache_read_only'],
    'capability_denied' => ['capability_denied'],
    'invalid_patch' => ['invalid_patch'],
    'rate_limited' => ['rate_limited'],
    'timeout' => ['timeout'],
] as $status => $statuses) {
    [$result, $host, $context] = \Tests\runFixture($statuses);
    assertCancellationSame(false, $result->success, "{$status} must fail closed at the host API boundary.");
    assertCancellationSame(0, $result->data['programmes_updated'] ?? 0, "{$status} must not claim an unpublished update.");
    assertCancellationSame(1, count($host->applies), "{$status} must not retry through a direct cache adapter.");
    assertCancellationSame(['Processing canonical host EPG snapshot.'], $context->messages, "{$status} must not report a published patch batch.");
}

[$snapshotRejectedResult, $snapshotRejectedHost] = \Tests\runFixture(['applied'], ['plugin_not_enabled']);
assertCancellationSame(false, $snapshotRejectedResult->success, 'Unavailable or unauthorized host snapshots must fail closed.');
assertCancellationSame(0, count($snapshotRejectedHost->applies), 'A rejected host snapshot must not attempt a host apply or local fallback.');

$source = (string) file_get_contents(__DIR__.'/../Plugin.php');
foreach (['tmdb-cache.json', 'tmdb-season-cache.json', 'tmdb-images-cache.json'] as $allowedState) {
    assertCancellationSame(true, str_contains($source, $allowedState), "Plugin-owned TMDB lookup decision state {$allowedState} must remain available.");
}
foreach (['enrichment-state.json', 'enrichment-checkpoint', 'programmes-', 'metadata.json'] as $forbiddenState) {
    assertCancellationSame(false, str_contains($source, $forbiddenState), "Plugin must not persist host EPG state through {$forbiddenState}.");
}

$reflection = new ReflectionClass(AppLocalPlugins\EpgEnricher\Plugin::class);
foreach (['saveEpgEnrichmentState', 'saveEnrichmentCheckpoint', 'loadEnrichmentState', 'loadEnrichmentCheckpoint'] as $removedStateMethod) {
    assertCancellationSame(false, $reflection->hasMethod($removedStateMethod), "Removed host-cache state method {$removedStateMethod} must not be callable.");
}

echo "Host cancellation state tests passed.\n";
