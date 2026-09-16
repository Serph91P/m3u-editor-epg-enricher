<?php

// Retained regression entry point: cancellation remains before host apply; plugin state is TMDB-only.
require __DIR__.'/HostEnrichmentApiTest.php';

$source = file_get_contents(__DIR__.'/../Plugin.php');
foreach (['Enrichment cancelled before host apply.', 'tmdb-cache.json', 'tmdb-season-cache.json', 'tmdb-images-cache.json'] as $required) {
    if (! str_contains($source, $required)) {
        fwrite(STDERR, "Cancellation/TMDB-state regression missing: {$required}\n");
        exit(1);
    }
}
foreach (['enrichment-state.json', 'enrichment-checkpoint', 'programmes-'] as $forbidden) {
    if (str_contains($source, $forbidden)) {
        fwrite(STDERR, "Plugin must not persist host EPG state: {$forbidden}\n");
        exit(1);
    }
}

echo "Host cancellation-state regression tests passed.\n";
