<?php

use App\Models\Channel;
use App\Models\CustomPlaylist;
use App\Models\Epg;
use App\Models\EpgChannel;
use App\Models\MergedPlaylist;
use App\Models\Playlist;
use App\Models\PlaylistAlias;
use App\Services\EpgCacheEnrichmentService;
use AppLocalPlugins\EpgEnricher\Services\PreservedPrimaryProjection as Projection;
use AppLocalPlugins\EpgEnricher\Services\PreservedPrimaryRepository as Repository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

function primaryQueryContext(array $fixture, ?object $playlist = null, array $settings = []): object
{
    $playlist ??= $fixture['playlist'];

    return (object) ['playlist' => (object) ['id' => $playlist->id, 'type' => $playlist::class, 'user_id' => $playlist->user_id], 'playlistAuth' => null, 'settings' => $settings];
}

function primaryProjectionFixture(array $changes = []): array
{
    $f = primaryNativeSetup();
    $f['epg']->forceFill(['is_cached' => true])->save();
    $p = primaryProgramme($changes);
    primarySeed($f['epg'], [$p]);
    $p = app(EpgCacheEnrichmentService::class)->snapshot($f['context'], $f['epg'])['programmes'][0]['programme'];
    (new Repository)->reconcile($f['epg']->id, $p, $p, []);
    $f['programme'] = $p;

    return $f;
}

function primaryPayload(array $fixture, string $format = 'xmltv'): array
{
    return ['stream_ids' => [$fixture['channel']->id], 'start_timestamp' => strtotime('2030-01-01T00:00:00Z'), 'stop_timestamp' => strtotime('2030-01-03T00:00:00Z'), 'format' => $format];
}

it('maps authorized current raw slots to streams using hour shifts before date filtering', function (int $shift) {
    $f = primaryProjectionFixture(['start' => '2030-01-01T23:30:00Z', 'stop' => '2030-01-02T00:30:00Z']);
    $f['channel']->update(['tvg_shift' => $shift]);
    $payload = primaryPayload($f);
    $start = strtotime('2030-01-01T23:30:00Z') + $shift * 3600;
    $payload['start_timestamp'] = $start;
    $payload['stop_timestamp'] = $start + 3600;
    $rows = iterator_to_array((new Projection)->entries($payload, primaryQueryContext($f)));
    expect($rows)->toHaveCount(1)->and($rows[0]['start_timestamp'])->toBe($start)
        ->and($rows[0]['primary']['url'])->toBe($f['programme']['icon']);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
})->with([2, -3, 0]);

it('does not leak foreign disabled unbound source or overwritten entries', function () {
    $f = primaryProjectionFixture();
    $projection = new Projection;
    $payload = primaryPayload($f);
    expect(iterator_to_array($projection->entries($payload, primaryQueryContext($f, settings: ['overwrite_existing' => true]))))->toBe([]);
    $foreign = Playlist::factory()->create();
    expect(iterator_to_array($projection->entries($payload, primaryQueryContext($f, $foreign))))->toBe([]);
    $f['channel']->update(['enabled' => false]);
    expect(iterator_to_array($projection->entries($payload, primaryQueryContext($f))))->toBe([]);
    $f['channel']->update(['enabled' => true]);
    primarySeed($f['epg'], [primaryProgramme(['icon' => 'https://example.invalid/stale.jpg'])]);
    expect(iterator_to_array($projection->entries($payload, primaryQueryContext($f))))->toBe([]);
});

it('keeps identical raw channel ids source bound and independently maps two output streams', function () {
    $f = primaryProjectionFixture();
    $otherEpg = Epg::factory()->create(['user_id' => $f['user']->id, 'is_cached' => true]);
    $otherRaw = EpgChannel::factory()->create(['epg_id' => $otherEpg->id, 'channel_id' => 'synthetic']);
    $other = Channel::factory()->create(['user_id' => $f['user']->id, 'playlist_id' => $f['playlist']->id, 'enabled' => true, 'epg_channel_id' => $otherRaw->id]);
    primarySeed($otherEpg, [primaryProgramme()]);
    $second = Channel::factory()->create(['user_id' => $f['user']->id, 'playlist_id' => $f['playlist']->id, 'enabled' => true, 'epg_channel_id' => $f['raw']->id, 'tvg_shift' => 2]);
    $payload = primaryPayload($f);
    $payload['stream_ids'] = [$f['channel']->id, $other->id, $second->id];
    $rows = iterator_to_array((new Projection)->entries($payload, primaryQueryContext($f)));
    expect($rows)->toHaveCount(2);
    $byStream = array_column($rows, null, 'stream_id');
    expect($byStream)->not->toHaveKey($other->id);
    expect($byStream[$second->id]['start_timestamp'] - $byStream[$f['channel']->id]['start_timestamp'])->toBe(7200);
    primarySeed($f['epg'], [primaryProgramme(), primaryProgramme(['title' => 'Conflicting duplicate'])]);
    expect(iterator_to_array((new Projection)->entries($payload, primaryQueryContext($f))))->toBe([]);
});

it('uses the actual custom alias and merged playlist channel relations', function (string $kind) {
    $f = primaryProjectionFixture();
    if ($kind === 'custom' || $kind === 'custom-alias') {
        $out = CustomPlaylist::factory()->create(['user_id' => $f['user']->id]);
        $out->channels()->attach($f['channel']->id);
    } elseif ($kind === 'merged') {
        $out = MergedPlaylist::factory()->create(['user_id' => $f['user']->id]);
        $out->playlists()->attach($f['playlist']->id);
    } else {
        $out = $f['playlist'];
    }
    if (str_contains($kind, 'alias')) {
        $out = PlaylistAlias::query()->create(['name' => 'Synthetic Alias', 'uuid' => (string) Str::uuid(), 'user_id' => $f['user']->id, 'playlist_id' => $kind === 'alias' ? $out->id : null, 'custom_playlist_id' => $kind === 'custom-alias' ? $out->id : null]);
    }
    $rows = iterator_to_array((new Projection)->entries(primaryPayload($f), primaryQueryContext($f, $out)));
    expect($rows)->toHaveCount(1)->and($rows[0]['stream_id'])->toBe($f['channel']->id);
})->with(['custom', 'merged', 'alias', 'custom-alias']);
