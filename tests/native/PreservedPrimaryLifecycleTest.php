<?php

use App\Models\Epg;
use App\Plugins\Support\PluginExecutionContext;
use App\Services\EpgCacheEnrichmentService;
use App\Services\EpgCacheService;
use App\Settings\GeneralSettings;
use AppLocalPlugins\EpgEnricher\Plugin;
use AppLocalPlugins\EpgEnricher\Services\IdentityTmdbService;
use AppLocalPlugins\EpgEnricher\Services\PreservedPrimaryRepository as Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

it('protects a canonical scalar on match abstention noop and removes on overwrite replay', function () {
    $fixture = primaryNativeSetup(['enrich_categories' => false, 'enrich_descriptions' => false]);
    $p = primaryProgramme();
    primarySeed($fixture['epg'], [$p]);
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'offline-synthetic';
    app()->instance(IdentityTmdbService::class, new IdentityTmdbService($settings));
    Http::fake(['*' => Http::response(['page' => 1, 'total_pages' => 0, 'total_results' => 0, 'results' => []])]);
    $method = new ReflectionMethod(Plugin::class, 'doEnrich');
    $plugin = new Plugin;
    $result = $method->invoke($plugin, $fixture['epg']->id, [$fixture['playlist']->id], $fixture['context']);
    expect($result->status)->toBe('completed');
    $current = app(EpgCacheEnrichmentService::class)->snapshot($fixture['context'], $fixture['epg'])['programmes'][0]['programme'];
    expect((new Repository)->validated($fixture['epg']->id, $current, []))->toBe($p['icon']);
    $method->invoke($plugin, $fixture['epg']->id, [$fixture['playlist']->id], $fixture['context']);
    expect(DB::table(Repository::TABLE)->count())->toBe(1);
    $context = new PluginExecutionContext($fixture['plugin'], $fixture['context']->run, 'manual', false, null, $fixture['user'], ['replace_existing_artwork' => true]);
    $method->invoke($plugin, $fixture['epg']->id, [$fixture['playlist']->id], $context);
    expect(DB::table(Repository::TABLE)->count())->toBe(0);
});

it('retries stale writes against fresh canonical scalars without inheriting old url or rowid identity', function () {
    $f = primaryNativeSetup(['map_genres_to_epg_categories' => true, 'keyword_category_detection' => true]);
    $p = primaryProgramme(['title' => 'Breaking News']);
    primarySeed($f['epg'], [$p]);
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'offline-synthetic';
    app()->instance(IdentityTmdbService::class, new IdentityTmdbService($settings));
    $service = new class(app(EpgCacheService::class)) extends EpgCacheEnrichmentService
    {
        public int $calls = 0;

        public function apply(PluginExecutionContext $context, Epg $epg, array $patches): array
        {
            $this->calls++;
            if ($this->calls === 1) {
                parent::apply($context, $epg, [['id' => $patches[0]['id'], 'hash' => $patches[0]['hash'], 'changes' => ['icon' => 'https://example.invalid/fresh.jpg']]]);

                return ['status' => 'stale'];
            }
            foreach ($patches as $patch) {
                expect($patch['changes'])->not->toHaveKey('preserved_primary_url');
            }

            return parent::apply($context, $epg, $patches);
        }
    };
    app()->instance(EpgCacheEnrichmentService::class, $service);
    $result = (new ReflectionMethod(Plugin::class, 'doEnrich'))->invoke(new Plugin, $f['epg']->id, [$f['playlist']->id], $f['context']);
    expect($result->status)->toBe('completed')->and($service->calls)->toBe(2);
    $current = $service->snapshot($f['context'], $f['epg'])['programmes'][0]['programme'];
    expect($current['icon'])->toBe('https://example.invalid/fresh.jpg');
    expect((new Repository)->validated($f['epg']->id, $current, []))->toBe($current['icon']);
    primarySeed($f['epg'], [primaryProgramme(['title' => 'Different identity'])]);
    $rebuilt = $service->snapshot($f['context'], $f['epg'])['programmes'][0]['programme'];
    expect((new Repository)->validated($f['epg']->id, $rebuilt, []))->toBeNull();
    Http::assertNothingSent();
});

it('never sends host primary marker even when a legacy host advertises it', function () {
    $p = primaryProgramme();
    $patch = (new ReflectionMethod(Plugin::class, 'canonicalHostChanges'))->invoke(new Plugin, $p, $p, false, true);
    expect($patch)->toBe([]);
});
