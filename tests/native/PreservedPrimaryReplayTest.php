<?php

use AppLocalPlugins\EpgEnricher\Plugin;
use AppLocalPlugins\EpgEnricher\Services\{IdentityTmdbService, PreservedPrimaryRepository as Repository};
use App\Services\EpgCacheEnrichmentService;
use App\Settings\GeneralSettings;
use Illuminate\Support\Facades\{DB, Http, Schema};

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
