<?php

use App\Plugins\PluginSchemaManager;
use AppLocalPlugins\EpgEnricher\Services\PreservedPrimaryRepository as Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
    app(PluginSchemaManager::class)->apply($manifest['schema'] ?? []);
});

it('creates declared storage and upserts source scoped bindings from canonical readback', function () {
    expect(Schema::hasTable('plugin_epg_enricher_preserved_primary'))->toBeTrue();
    $repo = new Repository;
    $p = primaryProgramme();
    expect($repo->reconcile(1, $p, $p, []))->toBe('protected');
    $repo->reconcile(1, $p, $p, []);
    $repo->reconcile(2, $p, $p, []);
    expect(DB::table(Repository::TABLE)->count())->toBe(2);
    expect($repo->validated(1, $p, []))->toBe($p['icon']);
    $repo->reconcile(1, $p, $p, ['replace_existing_artwork' => true]);
    expect($repo->validated(1, $p, []))->toBeNull();
    expect($repo->validated(2, $p, []))->toBe($p['icon']);
});

it('never authorizes stale url identity slot or current overwrite settings', function (array $changes) {
    $repo = new Repository;
    $p = primaryProgramme();
    $repo->reconcile(1, $p, $p, []);
    expect($repo->validated(1, array_replace($p, $changes), []))->toBeNull();
    expect($repo->validated(1, $p, ['overwrite_existing' => true]))->toBeNull();
})->with([[['icon' => 'https://example.invalid/changed.jpg']], [['icon' => '']], [['title' => 'Other']], [['subtitle' => 'Other']], [['start' => '2030-01-01T12:01:00Z']]]);

it('rejects changed or invalid scalars and cleans only expired own rows', function () {
    $repo = new Repository;
    $p = primaryProgramme();
    foreach (['file:///private', '', 'ftp://example.invalid/a', 'not a url'] as $url) {
        expect($repo->reconcile(1, $p, primaryProgramme(['icon' => $url]), []))->toBe('unprotected');
    }
    expect($repo->reconcile(1, $p, primaryProgramme(['icon' => 'https://example.invalid/other.jpg']), []))->toBe('unprotected');
    $repo->reconcile(1, $p, $p, []);
    $repo->reconcile(2, $p, $p, []);
    DB::table(Repository::TABLE)->where('epg_id', 1)->update(['stop_timestamp' => 1]);
    expect($repo->cleanup())->toBe(1);
    expect(DB::table(Repository::TABLE)->count())->toBe(1);
});

it('reports a missing legacy table as unsupported rather than a fatal or successful protection', function () {
    Schema::dropIfExists('plugin_epg_enricher_preserved_primary');
    $repo = new Repository;
    $p = primaryProgramme();
    expect($repo->supported())->toBeFalse();
    expect($repo->reconcile(1, $p, $p, []))->toBe('unsupported');
    expect($repo->validated(1, $p, []))->toBeNull();
});
