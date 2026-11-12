<?php

namespace AppLocalPlugins\EpgEnricher\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use RuntimeException;

require_once __DIR__.'/PreservedPrimaryProjection.php';

class PreservedPrimaryQuery
{
    private const TTL = 120;

    private const PREFIX = 'epg-enricher:primary-v1:';

    private const PAGE_BYTES = 524288 - 1024; // Reserve the complete wire envelope and opaque cursors.

    public function __construct(private readonly PreservedPrimaryProjection $projection = new PreservedPrimaryProjection) {}

    public function execute(array $payload, object $context): array
    {
        $this->validate($payload);
        $cursor = $payload['cursor'] ?? null;
        unset($payload['cursor']);
        sort($payload['stream_ids'], SORT_NUMERIC);
        $binding = hash('sha256', json_encode([$payload, [
            $context->playlist->type, $context->playlist->id, $context->playlist->user_id,
            $context->playlistAuth->id ?? null, $context->playlistAuth->user_id ?? null,
            $context->settings,
        ]], JSON_THROW_ON_ERROR));
        if ($cursor !== null) {
            $record = Cache::get(self::PREFIX.'cursor:'.$cursor);
            if (! is_array($record) || $record['binding'] !== $binding) {
                $this->invalid();
            }
            $manifest = Cache::get(self::PREFIX.'manifest:'.$record['snapshot']);
            if (! is_array($manifest) || $manifest['binding'] !== $binding) {
                $this->invalid();
            }
            // Atomic across workers. A token is never a client-selected arbitrary cache key.
            if (! Cache::add(self::PREFIX.'used:'.$cursor, true, self::TTL)) {
                $this->invalid();
            }

            return $this->page($record['snapshot'], $record['page'], $manifest);
        }
        $snapshot = bin2hex(random_bytes(32));
        $buffer = [];
        $bytes = 2;
        $total = 0;
        $count = 0;
        $save = function () use (&$buffer, &$bytes, &$count, $snapshot): void {
            $this->put(self::PREFIX.'page:'.$snapshot.':'.$count, $buffer);
            $buffer = [];
            $bytes = 2;
            $count++;
        };
        foreach ($this->projection->entries($payload, $context) as $entry) {
            $size = strlen(json_encode($entry, JSON_THROW_ON_ERROR));
            if ($size + 2 > self::PAGE_BYTES) {
                throw new RuntimeException('Primary projection entry exceeds page budget.');
            }
            if ($buffer !== [] && (count($buffer) >= 256 || $bytes + $size + 1 > self::PAGE_BYTES)) {
                $save();
            }
            $buffer[] = $entry;
            $bytes += $size + 1;
            $total++;
        }
        if ($buffer !== [] || $count === 0) {
            $save();
        }
        $cursors = [null];
        for ($index = 1; $index < $count; $index++) {
            $token = bin2hex(random_bytes(32));
            $this->put(self::PREFIX.'cursor:'.$token, ['snapshot' => $snapshot, 'page' => $index, 'binding' => $binding]);
            $cursors[] = $token;
        }
        $manifest = ['binding' => $binding, 'total' => $total, 'pages' => $count, 'cursors' => $cursors];
        // No page can be consumed until the whole validated projection is durable.
        $this->put(self::PREFIX.'manifest:'.$snapshot, $manifest);

        return $this->page($snapshot, 0, $manifest);
    }

    private function put(string $key, array $value): void
    {
        if (! Cache::put($key, $value, self::TTL)) {
            throw new RuntimeException('Primary projection cache write failed.');
        }
    }

    private function page(string $snapshot, int $index, array $manifest): array
    {
        $entries = Cache::get(self::PREFIX.'page:'.$snapshot.':'.$index);
        if (! is_array($entries) || $index < 0 || $index >= $manifest['pages']) {
            $this->invalid();
        }

        return ['snapshot_id' => $snapshot, 'total' => $manifest['total'],
            'next_cursor' => $manifest['cursors'][$index + 1] ?? null, 'entries' => $entries];
    }

    private function validate(array $payload): void
    {
        if (array_diff(array_keys($payload), ['stream_ids', 'start_timestamp', 'stop_timestamp', 'format', 'cursor']) !== []
            || ! is_array($payload['stream_ids'] ?? null) || ! array_is_list($payload['stream_ids'])
            || count($payload['stream_ids']) < 1 || count($payload['stream_ids']) > 100
            || count(array_unique($payload['stream_ids'], SORT_REGULAR)) !== count($payload['stream_ids'])
            || ! is_int($payload['start_timestamp'] ?? null) || ! is_int($payload['stop_timestamp'] ?? null)
            || $payload['start_timestamp'] < 0 || $payload['stop_timestamp'] <= $payload['start_timestamp']
            || 14 * 86400 < $payload['stop_timestamp'] - $payload['start_timestamp']
            || ! in_array($payload['format'] ?? null, ['xmltv', 'json'], true)
            || (($payload['cursor'] ?? null) !== null && (! is_string($payload['cursor']) || preg_match('/^[a-f0-9]{64}$/D', $payload['cursor']) !== 1))) {
            $this->invalid();
        }
        foreach ($payload['stream_ids'] as $id) {
            if (! is_int($id) || $id < 1) {
                $this->invalid();
            }
        }
    }

    private function invalid(): never
    {
        throw ValidationException::withMessages(['payload' => 'Invalid primary projection parameters or expired cursor.']);
    }
}
