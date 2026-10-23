<?php
putenv('TMDB_FIXTURES_ONLY=1');
require __DIR__.'/TmdbArtworkRepairTest.php';

use AppLocalPlugins\EpgEnricher\Plugin;

$plugin = new Plugin();
$method = new ReflectionMethod($plugin, 'canonicalHostChanges');
$url = 'https://fixture.invalid/still.jpg';
$before = ['icon' => $url, 'images' => [['url' => $url, 'type' => 'still', 'width' => 1920, 'height' => 1080]]];
$patch = $method->invoke($plugin, $before, $before, false, true);
Tests\assertSameValue($url, $patch['preserved_primary_url'] ?? null, 'Preserve must explicitly transport the unchanged before scalar, even on artwork noop.');
$protected = array_replace($before, $patch);
Tests\assertSameValue([], $method->invoke($plugin, $protected, $protected, false, true), 'Preserve is idempotent.');
foreach ([true, false] as $overwrite) {
    $after = array_replace($protected, ['icon' => 'https://fixture.invalid/replacement.jpg']);
    Tests\assertSameValue('', $method->invoke($plugin, $protected, $after, $overwrite, true)['preserved_primary_url'] ?? null, 'Scalar change clears protection.');
}
Tests\assertSameValue('', $method->invoke($plugin, $protected, $protected, true, true)['preserved_primary_url'] ?? null, 'Enabling overwrite clears protection even when the URL is unchanged.');
$removed = $protected;
unset($removed['icon']);
$patch = $method->invoke($plugin, $protected, $removed, false, true);
Tests\assertSameValue('', $patch['preserved_primary_url'] ?? null, 'Removing a scalar clears protection.');
Tests\assertSameValue('', $patch['icon'] ?? null, 'Scalar removal must survive array_replace.');
Tests\assertSameValue([], $method->invoke($plugin, $before, $before, false, false), 'Old hosts never receive unknown fields.');
Tests\assertSameValue([], $method->invoke($plugin, ['images' => $before['images']], ['images' => $before['images']], false, true), 'Secondary-only is not protected.');
foreach (['not a URL', 'file:///private/image.jpg', 'ftp://fixture.invalid/image.jpg'] as $invalid) {
    $row = ['icon' => $invalid, 'preserved_primary_url' => $invalid];
    Tests\assertSameValue('', $method->invoke($plugin, $row, $row, false, true)['preserved_primary_url'] ?? null, 'Invalid bindings are explicitly cleared.');
}
echo "Preserved Primary canonical transport passed.\n";
