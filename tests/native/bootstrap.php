<?php

use AppLocalPlugins\EpgEnricher\Plugin;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\LoadConfiguration;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

$host = getenv('ENRICHER_HOST_ROOT');
if (! is_string($host) || ! is_file($host.'/vendor/autoload.php') || ! is_file($host.'/tests/TestCase.php')) {
    fwrite(STDERR, "ENRICHER_HOST_ROOT must provide the full installed host.\n");
    exit(2);
}
require_once $host.'/vendor/autoload.php';
require_once $host.'/tests/TestCase.php';
require_once dirname(__DIR__, 2).'/Plugin.php';
new Plugin;

abstract class EnricherNativeTestCase extends TestCase
{
    public function createApplication()
    {
        $app = require getenv('ENRICHER_HOST_ROOT').'/bootstrap/app.php';
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        // Never read an installation's .env or use its storage/cache/database.
        $scratch = getenv('ENRICHER_NATIVE_SCRATCH');
        $app->useEnvironmentPath($scratch);
        $app->useStoragePath($scratch.'/storage');
        foreach (['framework/views', 'framework/cache', 'framework/sessions', 'logs'] as $directory) {
            if (! is_dir($scratch.'/storage/'.$directory)) {
                mkdir($scratch.'/storage/'.$directory, 0700, true);
            }
        }
        $app->afterBootstrapping(LoadConfiguration::class, function ($app) {
            $app['config']->set('broadcasting.default', 'null');
            $app['config']->set('cache.default', 'array');
            $app['config']->set('queue.default', 'sync');
        });
        $app->make(Kernel::class)->bootstrap();
        $app['config']->set('database.connections.jobs', $app['config']->get('database.connections.sqlite_testing'));
        $app['config']->set('settings.cache.enabled', false);
        $app['config']->set('plugins.directories', [$scratch.'/plugins']);
        $app['config']->set('plugins.bundled_directories', []);
        $app['config']->set('plugins.staging_directory', $scratch.'/staging');
        $app['config']->set('plugins.update_check.enabled', false);

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Queue::fake();
    }
}
