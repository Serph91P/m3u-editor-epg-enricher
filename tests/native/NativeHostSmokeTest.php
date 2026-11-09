<?php

use App\Plugins\Contracts\EpgProcessorPluginInterface;
use AppLocalPlugins\EpgEnricher\Plugin;
use Illuminate\Support\Facades\Http;

it('loads the local plugin in an isolated full host without an opt in', function () {
    expect(new Plugin)->toBeInstanceOf(EpgProcessorPluginInterface::class);
    expect(config('database.default'))->toBe('sqlite_testing');
    expect(config('cache.default'))->toBe('array');
    expect(getenv('EPG_ENRICHER_SOURCE'))->toBeFalse();
    Http::assertNothingSent();
});
