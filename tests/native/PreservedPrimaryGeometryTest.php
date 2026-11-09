<?php

use App\Models\PlaylistAlias;
use App\Models\PlaylistAuth;
use AppLocalPlugins\EpgEnricher\Services\PreservedPrimaryProjection as Projection;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;

it('matches actual XMLTV first icon and JSON roles without inventing a JSON scalar or subtitle', function (bool $proxy, int $shift, string $kind = 'standard') {
    $this->travelTo(Carbon::parse('2030-01-02T12:00:00Z'));
    $f = primaryProjectionFixture(['start' => '2030-01-01T23:30:00Z', 'stop' => '2030-01-02T00:30:00Z', 'subtitle' => 'Synthetic Episode', 'images' => [
        ['url' => 'https://example.invalid/primary.jpg', 'type' => 'still', 'width' => 1920, 'height' => 1080, 'orient' => 'L'],
        ['url' => 'https://example.invalid/poster.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750, 'orient' => 'P'],
        ['url' => 'https://example.invalid/backdrop.jpg', 'type' => 'backdrop', 'width' => 1280, 'height' => 720, 'orient' => 'L'],
    ]]);
    $f['playlist'] = primaryOutputPlaylist($f, $kind);
    if ($f['playlist'] instanceof PlaylistAlias) {
        $f['playlist']->getEffectivePlaylist()->update(['dummy_epg' => false]);
    } else {
        $f['playlist']->update(['dummy_epg' => false]);
    }
    $f['playlist']->update(['enable_logo_proxy' => $proxy]);
    $f['channel']->update(['tvg_shift' => $shift]);
    $context = primaryQueryContext($f);
    $projection = new Projection;
    $rows = iterator_to_array($projection->entries(primaryPayload($f), $context));
    expect($rows)->toHaveCount(1);
    $row = $rows[0];
    $xml = gzdecode($this->get('/'.$f['playlist']->uuid.'/epg.xml.gz')->assertOk()->streamedContent());
    $document = new DOMDocument;
    $document->loadXML($xml);
    $xpath = new DOMXPath($document);
    expect($xpath->evaluate('string(//programme/icon[1]/@src)'))->toBe($row['guide']['primary_icon_url']);
    expect($xpath->evaluate('string(//programme/icon[1]/@width)'))->toBe('1920');
    expect(Carbon::parse($xpath->evaluate('string(//programme/@start)'))->timestamp)->toBe($row['start_timestamp']);
    expect(str_contains($xml, 'm3ue-preserved-primary'))->toBeFalse();
    $auth = PlaylistAuth::create(['name' => 'Synthetic', 'username' => 'synthetic-geometry', 'password' => 'synthetic-password', 'enabled' => true, 'user_id' => $f['user']->id]);
    $auth->assignTo($f['playlist']);
    $json = $this->getJson(route('xtream.api.player').'?'.http_build_query(['username' => $auth->username, 'password' => 'synthetic-password', 'action' => 'get_simple_data_table', 'stream_id' => $f['channel']->id]))->assertOk()->json('epg_listings.0');
    $payload = primaryPayload($f, 'json');
    $jsonRow = iterator_to_array($projection->entries($payload, $context))[0];
    expect($jsonRow['guide']['primary_icon_url'])->toBeNull()->and($jsonRow['subtitle'])->toBeNull();
    expect($json)->not->toHaveKey('icon')->not->toHaveKey('subtitle')->not->toHaveKey('preserved_primary_url');
    expect(base64_decode($json['title']))->toBe($jsonRow['title']);
    expect((int) $json['start_timestamp'])->toBe($jsonRow['start_timestamp']);
    foreach (['poster_url', 'poster_width', 'poster_height', 'backdrop_url', 'still_url', 'still_width', 'still_height'] as $key) {
        expect($json[$key])->toBe($jsonRow['guide'][$key]);
    }
    expect($row['primary']['width'])->toBe(1920)->and($row['primary']['height'])->toBe(1080);
    Http::assertNothingSent();
})->with([[false, 2], [true, 2], [false, -3], [true, -3], [true, 2, 'custom'], [true, 2, 'merged'], [true, 2, 'alias'], [true, 2, 'custom-alias']]);

it('never borrows poster dimensions for unknown or conflicting primary geometry', function (array $primaryImages) {
    $f = primaryProjectionFixture(['images' => [...$primaryImages, ['url' => 'https://example.invalid/poster.jpg', 'type' => 'poster', 'width' => 500, 'height' => 750]]]);
    $row = iterator_to_array((new Projection)->entries(primaryPayload($f), primaryQueryContext($f)))[0];
    expect($row['primary']['width'])->toBe(0)->and($row['primary']['height'])->toBe(0);
})->with([
    [[]],
    [[['url' => 'https://example.invalid/primary.jpg', 'type' => 'still', 'width' => 1920, 'height' => 1080], ['url' => 'https://example.invalid/primary.jpg', 'type' => 'still', 'width' => 800, 'height' => 600]]],
]);
