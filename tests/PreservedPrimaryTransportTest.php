<?php
putenv('TMDB_FIXTURES_ONLY=1');
require __DIR__.'/TmdbArtworkRepairTest.php';

use AppLocalPlugins\EpgEnricher\Plugin;

$plugin = new Plugin();
$method = new ReflectionMethod($plugin, 'canonicalHostChanges');
$url = 'https://fixture.invalid/still.jpg';
$before = ['icon' => $url, 'images' => [['url' => $url, 'type' => 'still', 'width' => 1920, 'height' => 1080]]];
foreach ([true, false] as $advertised) {
    foreach ([true, false] as $overwrite) {
        foreach ([$before, array_replace($before, ['icon' => 'https://fixture.invalid/replacement.jpg']), ['images' => $before['images']]] as $after) {
            $patch = $method->invoke($plugin, $before, $after, $overwrite, $advertised);
            Tests\assertSameValue(false, array_key_exists('preserved_primary_url', $patch), 'Core never receives plugin protection fields.');
            if (! isset($after['icon'])) {
                Tests\assertSameValue('', $patch['icon'] ?? null, 'Scalar removal remains a normal supported host change.');
            }
        }
    }
}
foreach (['not a URL', 'file:///private/image.jpg', 'ftp://fixture.invalid/image.jpg'] as $invalid) {
    $row = ['icon' => $invalid, 'preserved_primary_url' => $invalid];
    Tests\assertSameValue([], $method->invoke($plugin, $row, $row, false, true), 'Legacy host fields never authorize a plugin marker patch.');
}
Tests\assertSameValue([], $method->invoke($plugin, $before, $before, false, true), 'Artwork noop stays a host noop; native lifecycle tests prove plugin ledger protection.');
echo "Preserved Primary canonical transport passed.\n";
