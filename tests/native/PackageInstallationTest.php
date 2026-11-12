<?php

use App\Plugins\PluginManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

it('installs the real ZIP through the native archive pipeline and loads all services without the source checkout', function () {
    $root = dirname(__DIR__, 2);
    $archive = getenv('ENRICHER_NATIVE_SCRATCH').'/package.zip';
    $process = proc_open(['php', $root.'/scripts/package-plugin-fallback.php', $archive], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    expect(proc_close($process), $output)->toBe(0);
    config()->set('plugins.clamav.driver', 'fake');
    $manager = app(PluginManager::class);
    $review = $manager->stageArchiveReview($archive);
    $review = $manager->scanInstallReview($review);
    $this->assertSame('valid', $review->validation_status, json_encode($review->validation_errors));
    $manager->approveInstallReview($review, true);
    $plugin = $manager->findPluginById('epg-enricher');
    expect($plugin->installation_status)->toBe('installed');
    expect(Schema::hasTable('plugin_epg_enricher_preserved_primary'))->toBeTrue();
    $source = <<<'PHP'
require $argv[1].'/vendor/autoload.php';
require $argv[2].'/Plugin.php';
$plugin = new \AppLocalPlugins\EpgEnricher\Plugin;
if ($plugin->playlistQueryNames() !== ['preserved_primary_v1']) { exit(3); }
foreach (['IdentityTmdbService', 'PreservedPrimaryKey', 'PreservedPrimaryRepository', 'PreservedPrimaryProjection', 'PreservedPrimaryQuery'] as $name) {
    $file = (new ReflectionClass('AppLocalPlugins\\EpgEnricher\\Services\\'.$name))->getFileName();
    if (! str_starts_with($file, $argv[2].'/Services/')) { exit(4); }
}
echo 'Installed ZIP loaded independently';
PHP;
    $process = proc_open(['php', '-r', $source, getenv('ENRICHER_HOST_ROOT'), $plugin->path], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]).stream_get_contents($pipes[2]);
    foreach ($pipes as $pipe) {
        fclose($pipe);
    }
    expect(proc_close($process), $output)->toBe(0)->and($output)->toBe('Installed ZIP loaded independently');
    Http::assertNothingSent();
});
