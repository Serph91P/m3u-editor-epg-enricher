<?php

declare(strict_types=1);

if ($argc !== 2) {
    fwrite(STDERR, "Usage: php package-plugin-fallback.php <archive-path>\n");
    exit(2);
}

$root = realpath(dirname(__DIR__));
if ($root === false) {
    fwrite(STDERR, "Could not resolve plugin root.\n");
    exit(1);
}

$archivePath = $argv[1];
$archive = new ZipArchive;
if ($archive->open($archivePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Could not create {$archivePath}.\n");
    exit(1);
}

$excludedDirectories = ['.git', '.github', '.hermes', '.serena', 'dist', 'tests', 'scripts'];
$excludedRootFiles = ['.gitignore', 'README.md', 'AGENTS.md', 'CLAUDE.md', '.DS_Store'];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::LEAVES_ONLY,
);

foreach ($iterator as $file) {
    if (! $file->isFile() || $file->isLink()) {
        continue;
    }

    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
    $segments = explode('/', $relative);
    if (in_array($segments[0], $excludedDirectories, true)
        || (count($segments) === 1 && in_array($relative, $excludedRootFiles, true))
        || str_starts_with($file->getFilename(), '._')) {
        continue;
    }

    if (! $archive->addFile($file->getPathname(), $relative)) {
        $archive->close();
        fwrite(STDERR, "Could not add {$relative}.\n");
        exit(1);
    }
}

if (! $archive->close()) {
    fwrite(STDERR, "Could not finalize {$archivePath}.\n");
    exit(1);
}
