<?php

it('loads the local plugin in an isolated full host without an opt in', function () {
    expect(new \AppLocalPlugins\EpgEnricher\Plugin)->toBeInstanceOf(\App\Plugins\Contracts\EpgProcessorPluginInterface::class);
    expect(config('database.default'))->toBe('sqlite_testing');
    expect(config('cache.default'))->toBe('array');
    expect(getenv('EPG_ENRICHER_SOURCE'))->toBeFalse();
    \Illuminate\Support\Facades\Http::assertNothingSent();
});
