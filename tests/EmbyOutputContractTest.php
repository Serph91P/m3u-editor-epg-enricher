<?php

require __DIR__.'/HostEnrichmentApiTest.php';

use AppLocalPlugins\EpgEnricher\Plugin;

function assertOutputSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, $message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
        exit(1);
    }
}

[$publishedResult, $publishedHost, $publishedContext] = \Tests\runFixture(['applied']);
assertOutputSame('completed', $publishedResult->status, 'An accepted host conditional apply must complete enrichment.');
assertOutputSame(1, $publishedResult->data['programmes_updated'] ?? null, 'Only an accepted host apply may report a programme update.');
assertOutputSame(1, count($publishedHost->applies), 'A changed canonical programme must be submitted to the host exactly once.');
assertOutputSame('token-1', $publishedHost->applies[0]['token'] ?? null, 'Host apply must use the matching opaque snapshot token.');
assertOutputSame('programme:MQ==', $publishedHost->applies[0]['patches'][0]['locator'] ?? null, 'Host apply must preserve the opaque programme locator.');
assertOutputSame('revision-1', $publishedHost->applies[0]['patches'][0]['row_revision'] ?? null, 'Host apply must preserve the canonical row revision.');
assertOutputSame('Sports', $publishedHost->applies[0]['patches'][0]['changes']['category'] ?? null, 'The canonical host patch must carry the enriched category.');
assertOutputSame(['Processing canonical host EPG snapshot.', 'Host EPG patch batch published.'], $publishedContext->messages, 'Publication status must be observable only after host acceptance.');

[$noopResult, $noopHost, $noopContext] = \Tests\runFixture(['noop']);
assertOutputSame('completed', $noopResult->status, 'A host no-op is a completed non-mutation, not a false failure.');
assertOutputSame(0, $noopResult->data['programmes_updated'] ?? null, 'A host no-op must not report an update.');
assertOutputSame(1, count($noopHost->applies), 'A host no-op must still be decided by the host, not by XMLTV output code.');
assertOutputSame(['Processing canonical host EPG snapshot.'], $noopContext->messages, 'No-op work must not claim that a patch batch was published.');

$changes = (new ReflectionMethod(Plugin::class, 'canonicalHostChanges'))->invoke(
    new Plugin(),
    ['images' => []],
    ['images' => [
        ['url' => 'https://fixture.invalid/poster.jpg', 'type' => 'poster', 'width' => 1000, 'height' => 1500, 'orient' => 'P', 'size' => 10],
        ['url' => 'https://fixture.invalid/backdrop.jpg', 'type' => 'backdrop', 'width' => 1920, 'height' => 1080, 'orient' => 'L', 'size' => 20],
        ['url' => 'https://fixture.invalid/still.jpg', 'type' => 'screenshot', 'width' => 1280, 'height' => 720, 'orient' => 'L', 'size' => 30],
        ['url' => 'https://fixture.invalid/logo.png', 'type' => 'logo', 'width' => 800, 'height' => 300, 'orient' => 'L', 'size' => 40],
    ]],
);
assertOutputSame(['poster', 'fanart', 'banner', 'logo'], array_column($changes['images'] ?? [], 'type'), 'Poster, backdrop, still, and logo must map to separate canonical host roles.');
assertOutputSame(['https://fixture.invalid/poster.jpg', 'https://fixture.invalid/backdrop.jpg', 'https://fixture.invalid/still.jpg', 'https://fixture.invalid/logo.png'], array_column($changes['images'] ?? [], 'url'), 'Canonical artwork patches must retain role-specific URLs.');
assertOutputSame(['P', 'L', 'L', 'L'], array_column($changes['images'] ?? [], 'orient'), 'Canonical artwork patches must retain orientation metadata.');

$tokens = token_get_all((string) file_get_contents(__DIR__.'/../Plugin.php'));
$code = '';
foreach ($tokens as $token) {
    $code .= is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true) ? '' : (is_array($token) ? $token[1] : $token);
}
foreach (['EpgCacheService', 'requestPlaylistXmltv', 'playlist-epg-files', 'clearPlaylistEpgCacheFile'] as $forbidden) {
    assertOutputSame(false, str_contains($code, $forbidden), "Plugin must delegate {$forbidden} output handling to the host.");
}

echo "Host output contract tests passed.\n";
