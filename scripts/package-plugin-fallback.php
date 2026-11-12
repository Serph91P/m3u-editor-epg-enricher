<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php package-plugin-fallback.php <archive-path>\n");
    exit(2);
}
$root = dirname(__DIR__);
$archive = new ZipArchive;
if ($archive->open($argv[1], ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    throw new RuntimeException('Could not create plugin archive.');
}
$files = [$root.'/Plugin.php', $root.'/plugin.json', $root.'/README.md', ...glob($root.'/Services/*.php')];
foreach ($files as $file) {
    if (! is_file($file) || is_link($file) || ! $archive->addFile($file, substr($file, strlen($root) + 1))) {
        throw new RuntimeException('Could not add runtime file.');
    }
}
if (! $archive->close()) {
    throw new RuntimeException('Could not finalize plugin archive.');
}
