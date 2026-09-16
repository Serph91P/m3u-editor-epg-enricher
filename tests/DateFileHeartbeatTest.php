<?php

require __DIR__.'/HostEnrichmentApiTest.php';

use AppLocalPlugins\EpgEnricher\Plugin;

function assertHeartbeatSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
        exit(1);
    }
}

$rows = [];
foreach (range(1, 500) as $index) {
    $rows[] = [
        'locator' => 'programme:'.base64_encode((string) $index),
        'row_revision' => 'revision-'.$index,
        'programme' => ['channel' => 'target', 'title' => ''],
    ];
}
$pages = array_map(
    fn (array $page, int $offset): array => [
        'programmes' => $page,
        'next_cursor' => $offset < 4 ? 'cursor-'.($offset + 1) : null,
    ],
    array_chunk($rows, 100),
    array_keys(array_chunk($rows, 100)),
);

[$largeResult, $largeHost, $largeContext] = \Tests\runFixture(['applied'], [], PHP_INT_MAX, $pages);
assertHeartbeatSame(500, $largeResult->data['programmes_processed'] ?? null, 'A 500-programme host fixture must process every canonical row.');
assertHeartbeatSame(0, $largeResult->data['tmdb_lookups'] ?? null, 'Empty canonical titles must not trigger TMDB lookups during a bounded scan.');
assertHeartbeatSame(5, count($largeHost->snapshots), 'The plugin must page a 500-programme snapshot in five host-bounded requests.');
assertHeartbeatSame(['limit' => 100], $largeHost->snapshots[0], 'The first host snapshot must use the public maximum page size.');
assertHeartbeatSame('cursor-1', $largeHost->snapshots[1]['cursor'] ?? null, 'The second host snapshot must continue from the opaque host cursor.');
assertHeartbeatSame(0, count($largeHost->applies), 'A no-op 500-programme scan must not publish an empty patch batch.');
assertHeartbeatSame(5, count($largeContext->messages), 'Every host snapshot page must emit an observable heartbeat.');
assertHeartbeatSame('Processing canonical host EPG snapshot.', $largeContext->messages[0] ?? null, 'Heartbeat text must describe host-snapshot processing.');

[$staleResult, $staleHost, $staleContext] = \Tests\runFixture(['stale_snapshot', 'applied']);
assertHeartbeatSame('completed', $staleResult->status, 'One stale host snapshot must recover through the bounded retry.');
assertHeartbeatSame(2, count($staleHost->snapshots), 'A stale apply must re-snapshot exactly once.');
assertHeartbeatSame(2, count($staleHost->applies), 'A stale apply must retry exactly once and never write directly.');
assertHeartbeatSame(1, $staleResult->data['programmes_updated'] ?? null, 'Only the accepted retry may count an update.');
assertHeartbeatSame(['Processing canonical host EPG snapshot.', 'Host EPG patch batch published.'], $staleContext->messages, 'Retry lifecycle heartbeats must remain truthful.');

[$cancelledResult, $cancelledHost, $cancelledContext] = \Tests\runFixture(['applied'], [], 1);
assertHeartbeatSame('cancelled', $cancelledResult->status, 'Cancellation during a host snapshot must propagate to the caller.');
assertHeartbeatSame(0, count($cancelledHost->applies), 'Cancellation before conditional apply must leave the host generation untouched.');
assertHeartbeatSame(0, $cancelledResult->data['programmes_updated'] ?? 0, 'Cancelled work must not report unpersisted updates.');
assertHeartbeatSame(['Processing canonical host EPG snapshot.'], $cancelledContext->messages, 'Cancellation must retain the snapshot heartbeat but not a publication heartbeat.');

$pluginReflection = new ReflectionClass(Plugin::class);
foreach (['processDateFile', 'processSqliteStore', 'invalidatePlaylistEpgCaches'] as $removedAdapter) {
    assertHeartbeatSame(false, $pluginReflection->hasMethod($removedAdapter), "Direct cache adapter {$removedAdapter} must not remain callable.");
}

$tokens = token_get_all((string) file_get_contents(__DIR__.'/../Plugin.php'));
$code = '';
foreach ($tokens as $token) {
    $code .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token);
}
foreach (['programmes.sqlite', 'new PDO', '.jsonl', 'playlist-epg-files'] as $forbidden) {
    assertHeartbeatSame(false, str_contains($code, $forbidden), "Plugin executable code must not access {$forbidden}.");
}

echo "Host snapshot heartbeat tests passed.\n";
