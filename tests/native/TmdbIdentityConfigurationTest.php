<?php

use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use AppLocalPlugins\EpgEnricher\Plugin;
use AppLocalPlugins\EpgEnricher\Services\IdentityTmdbService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

it('shares the parent limiter budget and honours plugin language without global rebinding', function () {
    Cache::flush();
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'offline-synthetic';
    $settings->tmdb_language = 'en-US';
    $settings->tmdb_rate_limit = 40;
    $local = new IdentityTmdbService($settings);
    $parent = new TmdbService($settings);
    $method = new ReflectionMethod(Plugin::class, 'setTmdbLanguage');
    $method->invoke(new Plugin, $local, 'de-DE');
    RateLimiter::shouldReceive('tooManyAttempts')->with('tmdb-api-rate-limit', 40)->twice()->andReturnFalse();
    RateLimiter::shouldReceive('hit')->with('tmdb-api-rate-limit', 1)->twice()->andReturn(1);
    Http::fake(['*' => Http::response(['page' => 1, 'total_pages' => 0, 'total_results' => 0, 'results' => []])]);
    expect($local->searchIdentityCandidates('tv', 'Synthetic'))->toBe(['status' => 'complete', 'candidates' => []]);
    (new ReflectionMethod(TmdbService::class, 'waitForRateLimit'))->invoke($parent);
    expect((new ReflectionProperty(TmdbService::class, 'language'))->getValue($parent))->toBe('en-US');
    $local->searchIdentityCandidates('tv', 'Synthetic');
    Http::assertSentCount(1);
    Http::assertSent(fn ($r) => $r['language'] === 'de-DE');
    expect(app(TmdbService::class)::class)->toBe(TmdbService::class);
});

it('makes no request or limiter reservation without an api key', function () {
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = '';
    $service = new IdentityTmdbService($settings);
    RateLimiter::shouldReceive('hit')->never();
    expect($service->searchIdentityCandidates('tv', 'Synthetic')['status'])->toBe('error');
    expect($service->getIdentityAlternativeTitles('tv', 1)['status'])->toBe('error');
    expect($service->getIdentityTranslations('tv', 1)['status'])->toBe('error');
    Http::assertNothingSent();
});
