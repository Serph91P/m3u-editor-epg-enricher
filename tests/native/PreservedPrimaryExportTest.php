<?php

use App\Models\PlaylistAuth;
use App\Models\PluginRun;
use App\Plugins\PluginManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

function primaryHttpFixture(): array
{
    $f = primaryProjectionFixture(['subtitle' => 'Synthetic Episode', 'images' => [
        ['url' => 'https://example.invalid/primary.jpg', 'type' => 'still', 'width' => 1920, 'height' => 1080, 'orient' => 'L'],
        ['url' => 'https://example.invalid/poster.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750, 'orient' => 'P'],
        ['url' => 'https://example.invalid/backdrop.jpg', 'type' => 'backdrop', 'width' => 1280, 'height' => 720, 'orient' => 'L'],
    ]]);
    $f['channel']->update(['stream_id' => 'synthetic-output', 'title' => 'Synthetic Channel', 'name' => 'Synthetic Channel', 'channel' => 1, 'logo' => 'https://example.invalid/channel.jpg']);
    $f['raw']->update(['lang' => 'en']);
    $f['playlist']->update(['enable_logo_proxy' => false, 'dummy_epg' => false, 'xapi_enabled' => true]);
    $manager = app(PluginManager::class);
    $plugin = $manager->validate($f['plugin']);
    test()->assertSame([], $plugin->validation_errors);
    $plugin->update(['trusted_hashes' => $plugin->only(['manifest_hash', 'entrypoint_hash', 'plugin_hash']), 'trust_state' => 'trusted', 'enabled' => true]);
    $f['plugin'] = $plugin->fresh();
    $auth = PlaylistAuth::create(['name' => 'Synthetic', 'username' => 'synthetic-export', 'password' => 'synthetic-password', 'enabled' => true, 'user_id' => $f['user']->id]);
    $auth->assignTo($f['playlist']);
    $f['body'] = ['username' => $auth->username, 'password' => 'synthetic-password', 'action' => 'plugin_query', 'plugin_id' => 'epg-enricher', 'query' => 'preserved_primary_v1'];

    return $f;
}

it('runs the real authenticated controller manager enricher chain and exports the same guide contract', function () {
    $this->travelTo(Carbon::parse('2030-01-01T12:00:00Z'));
    $f = primaryHttpFixture();
    Bus::fake();
    $beforeRuns = PluginRun::count();
    $payload = primaryPayload($f);
    $response = $this->post('/player_api.php', $f['body'] + ['payload' => json_encode($payload)])->assertOk();
    $response->assertJsonPath('api_version', 1)->assertJsonPath('plugin_id', 'epg-enricher')->assertJsonPath('query', 'preserved_primary_v1')->assertJsonPath('data.total', 1);
    $row = $response->json('data.entries.0');
    $xml = gzdecode($this->get('/'.$f['playlist']->uuid.'/epg.xml.gz')->assertOk()->streamedContent());
    $doc = new DOMDocument;
    $doc->loadXML($xml);
    $xpath = new DOMXPath($doc);
    expect($xpath->evaluate('string(//programme/icon[1]/@src)'))->toBe($row['primary']['url']);
    expect($row['primary'])->toBe(['url' => 'https://example.invalid/primary.jpg', 'width' => 1920, 'height' => 1080]);
    expect($row['guide']['poster_url'])->toBe('https://example.invalid/poster.jpg');
    expect(str_contains($xml, 'm3ue-preserved-primary'))->toBeFalse();
    $json = $this->getJson('/player_api.php?'.http_build_query(['username' => 'synthetic-export', 'password' => 'synthetic-password', 'action' => 'get_simple_data_table', 'stream_id' => $f['channel']->id]))->assertOk()->json('epg_listings.0');
    $payload['format'] = 'json';
    $jsonResponse = $this->post('/player_api.php', $f['body'] + ['payload' => json_encode($payload)])->assertOk();
    expect($jsonResponse->json('data.entries.0.guide.primary_icon_url'))->toBeNull();
    expect($jsonResponse->json('data.entries.0.subtitle'))->toBeNull();
    expect($json)->not->toHaveKey('preserved_primary_url')->not->toHaveKey('icon')->not->toHaveKey('subtitle');
    expect($json['poster_url'])->toBe($row['guide']['poster_url']);
    expect(base64_decode($json['title']))->toBe($row['title']);
    expect(PluginRun::count())->toBe($beforeRuns);
    Http::assertNothingSent();
    Bus::assertNothingDispatched();
    // Reproducible synthetic wire evidence for the separate Emby consumer contract.
    $contract = ['api_version' => 1, 'xmltv' => $xml, 'json' => $json,
        'xmltv_projection' => $response->json('data.entries'), 'json_projection' => $jsonResponse->json('data.entries')];
    expect($contract)->toBe(json_decode(file_get_contents(__DIR__.'/fixtures/preserved-primary-v1.json'), true, flags: JSON_THROW_ON_ERROR));
    if ($output = getenv('ENRICHER_CONTRACT_OUTPUT')) {
        file_put_contents($output, json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
    }
});

it('enforces actual query allowlist disabled and current overwrite or stale protection boundaries', function () {
    $f = primaryHttpFixture();
    $payload = primaryPayload($f);
    $body = $f['body'] + ['payload' => json_encode($payload)];
    $this->post('/player_api.php', array_replace($body, ['query' => 'health_check']))->assertNotFound();
    $this->post('/player_api.php', array_replace($body, ['password' => 'invalid']))->assertUnauthorized();
    $this->post('/player_api.php', array_replace($body, ['payload' => json_encode(array_replace($payload, ['stream_ids' => [1, 1]]))]))->assertStatus(422);
    $f['plugin']->update(['settings' => ['overwrite_existing' => true]]);
    $this->post('/player_api.php', $body)->assertOk()->assertJsonPath('data.total', 0);
    $f['plugin']->update(['settings' => []]);
    primarySeed($f['epg'], [primaryProgramme(['icon' => 'https://example.invalid/changed.jpg'])]);
    $this->post('/player_api.php', $body)->assertOk()->assertJsonPath('data.total', 0);
    $f['plugin']->update(['enabled' => false]);
    $this->post('/player_api.php', $body)->assertForbidden();
    Http::assertNothingSent();
});
