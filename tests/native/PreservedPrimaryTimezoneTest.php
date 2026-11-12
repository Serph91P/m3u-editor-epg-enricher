<?php

use App\Services\EpgCacheEnrichmentService;
use App\Services\EpgCacheService;
use App\Settings\GeneralSettings;
use AppLocalPlugins\EpgEnricher\Services\PreservedPrimaryRepository;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

it('projects real imported local cache buckets into the exact shifted XMLTV and JSON slots', function (string $timezone, string $rawStart, string $rawStop, string $bucket, int $shift, string $wireStart, string $wireStop) {
    $previous = date_default_timezone_get();
    try {
        $f = primaryHttpFixture();
        $general = app(GeneralSettings::class);
        $general->app_timezone = $timezone;
        app()->instance(GeneralSettings::class, $general);
        config(['app.timezone' => $timezone]);
        date_default_timezone_set($timezone);
        $this->travelTo(Carbon::parse('2030-01-02T12:00:00Z'));
        $f['epg']->update(['url' => 'https://example.invalid/synthetic.xml']);
        $f['channel']->update(['tvg_shift' => $shift]);
        $xml = '<?xml version="1.0"?><tv><channel id="synthetic"><display-name>Synthetic</display-name></channel><programme channel="synthetic" start="'.$rawStart.'" stop="'.$rawStop.'"><title>Synthetic Boundary</title><icon src="https://example.invalid/primary.jpg"/></programme></tv>';
        Storage::disk('local')->put($f['epg']->file_path, gzencode($xml));
        $cache = new EpgCacheService;
        expect($cache->cacheEpgData($f['epg']))->toBeTrue();
        $f['epg']->refresh();
        expect($cache->getCachedProgrammes($f['epg'], $bucket, ['synthetic'])['synthetic'])->toHaveCount(1);
        $current = app(EpgCacheEnrichmentService::class)->snapshot($f['context'], $f['epg'])['programmes'][0]['programme'];
        expect((new PreservedPrimaryRepository)->reconcile($f['epg']->id, $current, $current, []))->toBe('protected');

        $start = Carbon::parse($wireStart)->timestamp;
        $stop = Carbon::parse($wireStop)->timestamp;
        $output = gzdecode($this->get('/'.$f['playlist']->uuid.'/epg.xml.gz')->assertOk()->streamedContent());
        $document = new DOMDocument;
        $document->loadXML($output);
        $xpath = new DOMXPath($document);
        expect($xpath->evaluate('count(//programme)'))->toBe(1.0);
        expect(Carbon::parse($xpath->evaluate('string(//programme/@start)'))->timestamp)->toBe($start);
        expect(Carbon::parse($xpath->evaluate('string(//programme/@stop)'))->timestamp)->toBe($stop);
        $json = $this->getJson('/player_api.php?'.http_build_query([
            'username' => $f['body']['username'], 'password' => $f['body']['password'],
            'action' => 'get_simple_data_table', 'stream_id' => $f['channel']->id,
        ]))->assertOk()->json('epg_listings');
        expect($json)->toHaveCount(1);
        expect((int) $json[0]['start_timestamp'])->toBe($start);
        expect((int) $json[0]['stop_timestamp'])->toBe($stop);
        foreach (['xmltv', 'json'] as $format) {
            $payload = ['stream_ids' => [$f['channel']->id], 'start_timestamp' => $start, 'stop_timestamp' => $stop, 'format' => $format];
            $response = $this->post('/player_api.php', $f['body'] + ['payload' => json_encode($payload)])->assertOk();
            $response->assertJsonPath('data.total', 1);
            $rows = $response->json('data.entries');
            expect($rows)->toHaveCount(1);
            expect($rows[0]['start_timestamp'])->toBe($start)->and($rows[0]['stop_timestamp'])->toBe($stop);
            expect($rows[0]['title'])->toBe('Synthetic Boundary');
            expect($rows[0]['primary']['url'])->toBe('https://example.invalid/primary.jpg');
            expect($rows[0]['guide']['primary_icon_url'])->toBe($format === 'xmltv' ? $xpath->evaluate('string(//programme/icon[1]/@src)') : null);
            // Reading a whole local bucket must not bypass the exact wire window filter.
            $payload['start_timestamp'] = $start + 1;
            $this->post('/player_api.php', $f['body'] + ['payload' => json_encode($payload)])->assertOk()->assertJsonPath('data.total', 0);
        }
        Http::assertNothingSent();
    } finally {
        date_default_timezone_set($previous);
    }
})->with([
    'east local midnight' => ['Europe/Berlin', '20300101231000 +0000', '20300101235000 +0000', '2030-01-02', 0, '2030-01-01T23:10:00Z', '2030-01-01T23:50:00Z'],
    'east positive shift' => ['Europe/Berlin', '20300101231000 +0000', '20300101235000 +0000', '2030-01-02', 2, '2030-01-02T01:10:00Z', '2030-01-02T01:50:00Z'],
    'west negative shift' => ['America/New_York', '20300102001000 +0000', '20300102005000 +0000', '2030-01-01', -3, '2030-01-01T21:10:00Z', '2030-01-01T21:50:00Z'],
    'UTC control' => ['UTC', '20300101231000 +0000', '20300101235000 +0000', '2030-01-01', 0, '2030-01-01T23:10:00Z', '2030-01-01T23:50:00Z'],
    'UTC positive shift' => ['UTC', '20300101231000 +0000', '20300101235000 +0000', '2030-01-01', 2, '2030-01-02T01:10:00Z', '2030-01-02T01:50:00Z'],
    'UTC negative shift' => ['UTC', '20300102001000 +0000', '20300102005000 +0000', '2030-01-02', -3, '2030-01-01T21:10:00Z', '2030-01-01T21:50:00Z'],
]);
