<?php

use App\Models\{Channel, Epg, EpgChannel, Playlist, Plugin as HostPlugin, PluginRun, User};
use App\Plugins\Support\PluginExecutionContext;
use App\Plugins\PluginSchemaManager;
use App\Services\{EpgProgrammeStore, EpgCacheEnrichmentService};
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\{Storage, Bus};

function primaryProgramme(array $changes = []): array
{
    return array_replace(['channel' => 'synthetic', 'start' => '2030-01-01T12:00:00Z', 'stop' => '2030-01-01T13:00:00Z', 'title' => 'Synthetic Harbour', 'icon' => 'https://example.invalid/primary.jpg'], $changes);
}

function primaryNativeSetup(array $settings = []): array
{
    Storage::fake('local');
    Bus::fake();
    $manifest = json_decode(file_get_contents(dirname(__DIR__, 2).'/plugin.json'), true, flags: JSON_THROW_ON_ERROR);
    app(PluginSchemaManager::class)->apply($manifest['schema']);
    $user = User::factory()->create();
    $epg = Epg::factory()->create(['user_id' => $user->id]);
    $playlist = Playlist::factory()->create(['user_id' => $user->id]);
    $raw = EpgChannel::factory()->create(['epg_id' => $epg->id, 'channel_id' => 'synthetic']);
    $channel = Channel::factory()->create(['user_id' => $user->id, 'playlist_id' => $playlist->id, 'epg_channel_id' => $raw->id, 'enabled' => true, 'shift' => 0]);
    $plugin = HostPlugin::query()->create([
        'plugin_id' => 'epg-enricher', 'name' => 'Synthetic Enricher', 'version' => $manifest['version'],
        'api_version' => '1.0.0', 'capabilities' => $manifest['capabilities'], 'hooks' => [],
        'permissions' => $manifest['permissions'], 'schema_definition' => $manifest['schema'],
        'actions' => [], 'settings_schema' => $manifest['settings'], 'settings' => $settings,
        'data_ownership' => ['tables' => [], 'directories' => [], 'files' => []],
        'source_type' => 'local_directory', 'path' => dirname(__DIR__, 2),
        'available' => true, 'enabled' => true, 'installation_status' => 'installed',
        'trust_state' => 'trusted', 'validation_status' => 'valid', 'integrity_status' => 'verified',
    ]);
    $run = PluginRun::query()->create(['extension_plugin_id' => $plugin->id, 'user_id' => $user->id,
        'status' => 'running', 'trigger' => 'manual', 'invocation_type' => 'action', 'dry_run' => false, 'payload' => []]);
    $context = new PluginExecutionContext($plugin, $run, 'manual', false, null, $user, $settings);
    return compact('user', 'epg', 'playlist', 'raw', 'channel', 'plugin', 'context');
}

function primarySeed(Epg $epg, array $programmes): void
{
    $directory = "epg-cache/{$epg->uuid}/v2";
    Storage::disk('local')->put($directory.'/metadata.json', json_encode(['cache_created' => time(), 'cache_version' => 'v2']));
    $store = new EpgProgrammeStore;
    $store->beginWrite(Storage::disk('local')->path($directory.'/programmes.sqlite'));
    foreach ($programmes as $programme) {
        $start = CarbonImmutable::parse($programme['start']);
        $stop = CarbonImmutable::parse($programme['stop']);
        $store->insert($programme['channel'], $start->format('Y-m-d'), $start->timestamp, $stop->timestamp, $programme);
    }
    $store->finish();
}
