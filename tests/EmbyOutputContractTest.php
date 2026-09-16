<?php

// Retained regression entry point: the host publishes canonical patches and owns XMLTV invalidation.
require __DIR__.'/HostEnrichmentApiTest.php';

$source = file_get_contents(__DIR__.'/../Plugin.php');
foreach (['EpgCacheEnrichmentService::class', "=== 'applied'", 'canonicalHostChanges'] as $required) {
    if (! str_contains($source, $required)) {
        fwrite(STDERR, "Host output-contract regression missing: {$required}\n");
        exit(1);
    }
}
foreach (['EpgCacheService', 'playlist-epg-files', 'requestPlaylistXmltv'] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fwrite(STDERR, "Plugin must not access host XMLTV output: {$forbidden}\n");
        exit(1);
    }
}

echo "Host output contract regression tests passed.\n";
