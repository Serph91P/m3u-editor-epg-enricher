<?php

use App\Services\EpgCacheEnrichmentService;
use App\Settings\GeneralSettings;
use AppLocalPlugins\EpgEnricher\Plugin;
use AppLocalPlugins\EpgEnricher\Services\IdentityTmdbService;
use AppLocalPlugins\EpgEnricher\Services\PreservedPrimaryRepository as Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;

it('reconciles protection through a real forward miss later match and cache replay without more provider calls', function () {
    $f = primaryNativeSetup(['enrich_categories' => true, 'enrich_descriptions' => false, 'enrich_posters' => false, 'enrich_backdrops' => false, 'enrich_episode_details' => false, 'keyword_category_detection' => false]);
    $p = primaryProgramme(['title' => 'Synthetic Atlas', 'episode_num' => 'EP123']);
    $later = array_replace($p, ['start' => '2030-01-01T14:00:00Z', 'stop' => '2030-01-01T15:00:00Z']);
    primarySeed($f['epg'], [$p, $later]);
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'offline-synthetic';
    app()->instance(IdentityTmdbService::class, new IdentityTmdbService($settings));
    $searches = 0;
    Http::fake(function ($request) use (&$searches) {
        $path = parse_url($request->url(), PHP_URL_PATH);
        if (str_ends_with($path, '/search/tv')) {
            if (++$searches === 1) {
                return Http::response([], 429);
            }

            return Http::response(['page' => 1, 'total_pages' => 1, 'total_results' => 1, 'results' => [['id' => 992006, 'name' => 'Synthetic Atlas', 'original_name' => 'Synthetic Atlas', 'first_air_date' => '2020-01-01', 'overview' => '']]]);
        }

        return Http::response(match (true) {
            str_ends_with($path, '/alternative_titles') => ['id' => 992006, 'results' => []],
            str_ends_with($path, '/translations') => ['id' => 992006, 'translations' => []],
            str_ends_with($path, '/tv/992006') => ['id' => 992006, 'name' => 'Synthetic Atlas', 'original_name' => 'Synthetic Atlas', 'first_air_date' => '2020-01-01', 'genres' => [['id' => 18, 'name' => 'Drama']]],
            default => throw new RuntimeException('Unexpected synthetic endpoint'),
        });
    });
    $result = (new ReflectionMethod(Plugin::class, 'doEnrich'))->invoke(new Plugin, $f['epg']->id, [$f['playlist']->id], $f['context']);
    expect($result->status)->toBe('completed')->and($result->data['programmes_updated'])->toBe(2);
    $rows = app(EpgCacheEnrichmentService::class)->snapshot($f['context'], $f['epg'])['programmes'];
    foreach ($rows as $row) {
        expect($row['programme']['category'])->toBe('Drama');
        expect((new Repository)->validated($f['epg']->id, $row['programme'], []))->toBe($p['icon']);
    }
    expect(DB::table(Repository::TABLE)->count())->toBe(2);
    Http::assertSentCount(5);
});

it('does not transfer bindings across rebuilds with reused rowids or changed current settings', function () {
    $f = primaryNativeSetup();
    primarySeed($f['epg'], [primaryProgramme()]);
    $service = app(EpgCacheEnrichmentService::class);
    $p = $service->snapshot($f['context'], $f['epg'])['programmes'][0]['programme'];
    $repo = new Repository;
    $repo->reconcile($f['epg']->id, $p, $p, []);
    primarySeed($f['epg'], [primaryProgramme(['subtitle' => 'Different episode'])]);
    $new = $service->snapshot($f['context'], $f['epg'])['programmes'][0];
    expect($new['id'])->toBe(1);
    expect($repo->validated($f['epg']->id, $new['programme'], []))->toBeNull();
    expect($repo->validated($f['epg']->id, $p, ['overwrite_existing' => true]))->toBeNull();
});

it('rejects changed readback and reports storage failure rather than successful protection', function () {
    $f = primaryNativeSetup();
    primarySeed($f['epg'], [primaryProgramme()]);
    $service = app(EpgCacheEnrichmentService::class);
    $snapshot = $service->snapshot($f['context'], $f['epg']);
    $p = $snapshot['programmes'][0]['programme'];
    $repo = new Repository;
    $repo->reconcile($f['epg']->id, $p, $p, []);
    primarySeed($f['epg'], [primaryProgramme(['icon' => 'https://example.invalid/changed.jpg'])]);
    $method = new ReflectionMethod(Plugin::class, 'reconcilePrimaryPage');
    expect($method->invoke(new Plugin, $service, $f['epg'], $f['context'], $snapshot, [], ['synthetic'], 0))->toBeTrue();
    expect(DB::table(Repository::TABLE)->count())->toBe(0);
    primarySeed($f['epg'], [primaryProgramme()]);
    Schema::table(Repository::TABLE, fn ($table) => $table->dropColumn('identity_hash'));
    expect($method->invoke(new Plugin, $service, $f['epg'], $f['context'], $snapshot, [], ['synthetic'], 0))->toBeFalse();
});

it('cleans expired rows during a normal noop replay without touching unexpired other sources', function () {
    $f = primaryNativeSetup();
    $p = primaryProgramme(['title' => 'Breaking News']);
    primarySeed($f['epg'], [$p]);
    $repo = new Repository;
    $repo->reconcile(999, $p, $p, []);
    $repo->reconcile(998, $p, $p, []);
    DB::table(Repository::TABLE)->where('epg_id', 998)->update(['stop_timestamp' => 1]);
    $settings = new GeneralSettings;
    $settings->tmdb_api_key = 'offline-synthetic';
    app()->instance(IdentityTmdbService::class, new IdentityTmdbService($settings));
    Http::fake(['*' => Http::response(['page' => 1, 'total_pages' => 0, 'total_results' => 0, 'results' => []])]);
    $method = new ReflectionMethod(Plugin::class, 'doEnrich');
    $method->invoke(new Plugin, $f['epg']->id, [$f['playlist']->id], $f['context']);
    expect(DB::table(Repository::TABLE)->where('epg_id', 998)->count())->toBe(0);
    expect(DB::table(Repository::TABLE)->where('epg_id', 999)->count())->toBe(1);
});
