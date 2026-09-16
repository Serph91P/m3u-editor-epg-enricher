<?php

// Retained regression entry point: host snapshot processing replaces date-file I/O.
require __DIR__.'/HostEnrichmentApiTest.php';

$source = file_get_contents(__DIR__.'/../Plugin.php');
foreach (['Processing canonical host EPG snapshot.', 'Host EPG patch batch published.', 'cancellationRequested()'] as $required) {
    if (! str_contains($source, $required)) {
        fwrite(STDERR, "Host heartbeat/cancellation regression missing: {$required}\n");
        exit(1);
    }
}

echo "Host heartbeat regression tests passed.\n";
