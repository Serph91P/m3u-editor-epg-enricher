<?php

use App\Services\EpgCacheEnrichmentService;
use AppLocalPlugins\EpgEnricher\Services\PreservedPrimaryProjection as Projection;
use AppLocalPlugins\EpgEnricher\Services\PreservedPrimaryQuery as Query;
use AppLocalPlugins\EpgEnricher\Services\PreservedPrimaryRepository;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;

it('rejects malformed or overbroad inputs instead of truncating them', function (array $change) {
    $f = primaryProjectionFixture();
    expect(fn () => (new Query)->execute(array_replace(primaryPayload($f), $change), primaryQueryContext($f)))
        ->toThrow(ValidationException::class);
})->with([
    [['stream_ids' => []]], [['stream_ids' => range(1, 101)]], [['stream_ids' => [1, 1]]], [['stream_ids' => ['1']]], [['stream_ids' => [0]]],
    [['format' => 'csv']], [['start_timestamp' => 2, 'stop_timestamp' => 1]], [['start_timestamp' => 1, 'stop_timestamp' => 1209602]],
    [['cursor' => 'bad']], [['epg_id' => 1]],
]);

it('serves exact immutable full paging and rejects reused changed foreign or expired cursors', function () {
    $f = primaryProjectionFixture();
    $projection = new class extends Projection
    {
        public function entries(array $payload, object $context): iterable
        {
            for ($i = 0; $i < 601; $i++) {
                yield ['stream_id' => 1, 'title' => 'Synthetic '.$i, 'padding' => str_repeat('a', 2100)];
            }
        }
    };
    $query = new Query($projection);
    $payload = primaryPayload($f);
    $context = primaryQueryContext($f);
    $page = $query->execute($payload, $context);
    $firstCursor = $page['next_cursor'];
    $snapshot = $page['snapshot_id'];
    $rows = [];
    $pages = 0;
    while (true) {
        expect($page['snapshot_id'])->toBe($snapshot)->and($page['total'])->toBe(601);
        expect(count($page['entries']))->toBeLessThanOrEqual(256);
        expect(strlen(json_encode(['api_version' => 1, 'plugin_id' => 'epg-enricher', 'query' => 'preserved_primary_v1', 'data' => $page])))->toBeLessThanOrEqual(524288);
        $rows = [...$rows, ...$page['entries']];
        $pages++;
        if ($page['next_cursor'] === null) {
            break;
        }
        $page = $query->execute($payload + ['cursor' => $page['next_cursor']], $context);
    }
    expect($rows)->toHaveCount(601)->and(array_unique(array_column($rows, 'title')))->toHaveCount(601)->and($pages)->toBeGreaterThan(2);
    expect(fn () => $query->execute($payload + ['cursor' => $firstCursor], $context))->toThrow(ValidationException::class);
    $fresh = $query->execute($payload, $context)['next_cursor'];
    expect(fn () => $query->execute(array_replace($payload, ['format' => 'json', 'cursor' => $fresh]), $context))->toThrow(ValidationException::class);
    $foreign = clone $context;
    $foreign->playlistAuth = (object) ['id' => 999, 'user_id' => $f['user']->id];
    expect(fn () => $query->execute($payload + ['cursor' => $fresh], $foreign))->toThrow(ValidationException::class);
    $this->travel(121)->seconds();
    expect(fn () => $query->execute($payload + ['cursor' => $fresh], $context))->toThrow(ValidationException::class);
    Http::assertNothingSent();
    Queue::assertNothingPushed();
});

it('pages a real canonical protected cohort without truncating and keeps it immutable across later changes', function () {
    $f = primaryProjectionFixture();
    $programmes = [];
    $start = strtotime('2030-01-01T00:00:00Z');
    for ($i = 0; $i < 601; $i++) {
        $programmes[] = primaryProgramme(['title' => 'Synthetic '.$i.str_repeat('x', 2200), 'start' => gmdate('c', $start + $i * 1200), 'stop' => gmdate('c', $start + ($i + 1) * 1200)]);
    }
    primarySeed($f['epg'], $programmes);
    $service = app(EpgCacheEnrichmentService::class);
    $after = 0;
    do {
        $snapshot = $service->snapshot($f['context'], $f['epg'], $after, 500);
        foreach ($snapshot['programmes'] as $row) {
            (new PreservedPrimaryRepository)->reconcile($f['epg']->id, $row['programme'], $row['programme'], []);
        }
        $after = $snapshot['next'];
    } while ($after !== null);
    $payload = primaryPayload($f);
    $payload['stop_timestamp'] = $start + 14 * 86400;
    $query = new Query;
    Bus::fake();
    $writes = [];
    DB::listen(function ($event) use (&$writes) {
        if (preg_match('/^\\s*(insert|update|delete|create|drop)/i', $event->sql)) {
            $writes[] = $event->sql;
        }
    });
    $page = $query->execute($payload, primaryQueryContext($f));
    $snapshotId = $page['snapshot_id'];
    $all = [];
    $sizes = [];
    while (true) {
        expect($page['total'])->toBe(601)->and($page['snapshot_id'])->toBe($snapshotId);
        $sizes[] = strlen(json_encode($page));
        expect(count($page['entries']))->toBeLessThanOrEqual(256)->and(end($sizes))->toBeLessThanOrEqual(524288);
        $all = [...$all, ...$page['entries']];
        if ($page['next_cursor'] === null) {
            break;
        }
        $page = $query->execute($payload + ['cursor' => $page['next_cursor']], primaryQueryContext($f));
    }
    expect($all)->toHaveCount(601)->and(array_unique(array_column($all, 'title')))->toHaveCount(601);
    expect($writes)->toBe([]);
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});

it('never publishes a manifest after an interrupted partially cached projection', function () {
    $f = primaryProjectionFixture();
    Cache::flush();
    $projection = new class extends Projection
    {
        public function entries(array $payload, object $context): iterable
        {
            for ($i = 0; $i < 300; $i++) {
                yield ['title' => 'Synthetic '.$i];
            }
            throw new RuntimeException('Synthetic interrupted projection');
        }
    };
    expect(fn () => (new Query($projection))->execute(primaryPayload($f), primaryQueryContext($f)))->toThrow(RuntimeException::class);
    $keys = array_keys(Cache::getStore()->all());
    expect(count(array_filter($keys, fn ($key) => str_contains($key, ':page:'))))->toBe(1);
    expect(array_filter($keys, fn ($key) => str_contains($key, ':manifest:')))->toBe([]);
});

it('does not publish a partial snapshot on cache failures and supports an empty complete result', function () {
    $f = primaryProjectionFixture();
    $empty = new class extends Projection
    {
        public function entries(array $payload, object $context): iterable
        {
            return [];
        }
    };
    expect((new Query($empty))->execute(primaryPayload($f), primaryQueryContext($f)))->toMatchArray(['total' => 0, 'next_cursor' => null, 'entries' => []]);
    Cache::shouldReceive('put')->once()->andReturnFalse();
    expect(fn () => (new Query($empty))->execute(primaryPayload($f), primaryQueryContext($f)))->toThrow(RuntimeException::class);
});
