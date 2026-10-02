<?php

namespace {
    function app(string $class): object
    {
        return $GLOBALS['bindingHostServices'][$class] ?? throw new \RuntimeException("No fixture service for {$class}");
    }

    function now(): object
    {
        return new class { public function toIso8601String(): string { return '2026-10-02T23:00:00+00:00'; } };
    }

    function storage_path(string $path = ''): string
    {
        return sys_get_temp_dir().'/epg-enricher-provider-binding-host';
    }
}

namespace App\Plugins\Contracts {
    interface EpgProcessorPluginInterface {}
    interface HookablePluginInterface {}
    interface PluginSelectOptionsProviderInterface { public function selectOptions(string $provider, \App\Plugins\Support\PluginSelectOptionsContext $context): array; }
}

namespace App\Plugins\Support {
    class PluginSelectOptionsContext {}
    class PluginActionResult
    {
        public function __construct(public readonly string $status, public readonly bool $success, public readonly string $summary, public readonly array $data = []) {}
        public static function success(string $summary, array $data = []): self { return new self('completed', true, $summary, $data); }
        public static function failure(string $summary, array $data = []): self { return new self('failed', false, $summary, $data); }
        public static function cancelled(string $summary, array $data = []): self { return new self('cancelled', false, $summary, $data); }
    }
    class PluginExecutionContext
    {
        public array $settings = [
            'enrich_from_tmdb' => true,
            'overwrite_existing' => true,
            'enrich_categories' => false,
            'enrich_descriptions' => false,
            'enrich_posters' => true,
            'enrich_backdrops' => false,
            'map_genres_to_epg_categories' => false,
            'map_genres_to_kodi_guide_genres' => false,
            'keyword_category_detection' => false,
            'enrich_episode_details' => false,
            'tmdb_language' => 'de-DE',
        ];
        public array $messages = [];
        public bool $dryRun = false;
        public int $cancellationChecks = 0;
        public int $cancelAfterChecks = PHP_INT_MAX;
        public function cancellationRequested(): bool { return ++$this->cancellationChecks >= $this->cancelAfterChecks; }
        public function heartbeat(string $message, ?int $progress = null): void { $this->messages[] = $message; }
        public function info(string $message): void {}
        public function warning(string $message): void {}
    }
}

namespace App\Models {
    class FakeCollection
    {
        public function __construct(private array $values) {}
        public function filter(): self { return $this; }
        public function unique(): self { return $this; }
        public function values(): self { return $this; }
        public function all(): array { return $this->values; }
    }
    class FakeQuery
    {
        public function __call(string $name, array $arguments): self { return $this; }
        public function pluck(string $column): FakeCollection { return new FakeCollection($column === 'channel_id' ? ['target'] : [1]); }
    }
    class Channel { public static function query(): FakeQuery { return new FakeQuery(); } }
    class EpgChannel { public static function query(): FakeQuery { return new FakeQuery(); } }
    class Playlist {}
    class Epg
    {
        public string $name = 'Synthetic binding EPG';
        public function __construct(public int $id = 1) {}
        public static function find(int $id): self { return new self($id); }
    }
}

namespace App\Settings {
    class GeneralSettings
    {
        public string $tmdb_api_key = 'synthetic-key';
        public string $tmdb_language = 'de-DE';
    }
}

namespace Illuminate\Support\Facades {
    class Storage
    {
        public static array $files = [];
        public static function disk(string $name): self { return new self(); }
        public function makeDirectory(string $path): void { @mkdir($this->path($path), 0777, true); }
        public function path(string $path): string { return sys_get_temp_dir().'/epg-enricher-provider-binding-host/'.str_replace('/', '-', $path); }
        public function exists(string $path): bool { return array_key_exists($path, self::$files); }
        public function get(string $path): string { return self::$files[$path] ?? '{}'; }
        public function put(string $path, string $contents): bool { self::$files[$path] = $contents; return true; }
    }
    class FakeHttpResponse
    {
        public function __construct(private array $data) {}
        public function successful(): bool { return true; }
        public function json(): array { return $this->data; }
    }
    class Http
    {
        public static array $calls = [];
        public static array $posterOverrides = [];
        public static function timeout(int $seconds): self { return new self(); }
        public function get(string $url, array $query): FakeHttpResponse
        {
            self::$calls[] = compact('url', 'query');
            preg_match('~/tv/(\d+)/images$~', $url, $match);
            $id = (int) ($match[1] ?? 0);
            $poster = match ($id) {
                101 => ['/selected-101.jpg', 732, 1200],
                201 => ['/selected-201.jpg', 810, 1200],
                202 => ['/selected-202.jpg', 744, 1200],
                default => ["/selected-{$id}.jpg", 800, 1200],
            };
            if (array_key_exists($id, self::$posterOverrides)) {
                $image = self::$posterOverrides[$id];
            } else {
                $image = [
                    'file_path' => $poster[0],
                    'width' => $poster[1],
                    'height' => $poster[2],
                    'aspect_ratio' => $poster[1] / $poster[2],
                    'iso_639_1' => 'de',
                    'vote_average' => 6.0,
                    'vote_count' => 1,
                ];
            }
            return new FakeHttpResponse([
                'posters' => [$image],
                'backdrops' => [],
                'logos' => [],
            ]);
        }
    }
    class Log { public static function __callStatic(string $name, array $arguments): void {} }
}

namespace App\Services {
    class EpgCacheService { public function isCacheValid(object $epg): bool { return true; } }

    class TmdbService
    {
        protected string $language = '';
        public array $calls = [];
        public function isConfigured(): bool { return true; }

        public function searchTvSeriesCandidates(string $name, ?int $year = null, int $limit = 5): array
        {
            $this->calls[] = ['tv-candidates', $name];
            return match ($name) {
                'Atlas' => [$this->candidate(101, 'Atlas'), $this->candidate(102, 'Atlas')],
                'Conflict One' => [$this->candidate(201, 'Conflict One'), $this->candidate(211, 'Conflict One')],
                'Conflict Two' => [$this->candidate(202, 'Conflict Two'), $this->candidate(212, 'Conflict Two')],
                'Direct Show' => [$this->candidate(301, 'Direct Show')],
                default => [],
            };
        }

        public function searchMovieCandidates(string $title, ?int $year = null, int $limit = 5): array
        {
            $this->calls[] = ['movie-candidates', $title];
            if ($title !== 'Movie Only') { return []; }
            return [['tmdb_id' => 401, 'title' => 'Movie Only', 'original_title' => 'Movie Only', 'release_date' => '2020-01-01', 'overview' => 'Synthetic movie.']];
        }

        public function getSeasonDetails(int $tmdbId, int $season): ?array
        {
            $this->calls[] = ['season', $tmdbId, $season];
            $titles = [101 => 'Seed Episode', 102 => null, 201 => 'Conflict A', 211 => null, 202 => 'Conflict B', 212 => null];
            $title = $titles[$tmdbId] ?? null;
            return ['episodes' => $title === null ? [] : [['episode_number' => 2, 'name' => $title]]];
        }

        public function getTvSeriesDetails(int $tmdbId): ?array
        {
            $this->calls[] = ['tv-details', $tmdbId];
            $names = [101 => 'Atlas', 201 => 'Conflict One', 202 => 'Conflict Two', 301 => 'Direct Show'];
            return isset($names[$tmdbId]) ? $this->tvDetails($tmdbId, $names[$tmdbId]) : null;
        }

        public function getMovieDetails(int $tmdbId): ?array
        {
            $this->calls[] = ['movie-details', $tmdbId];
            return $tmdbId === 401 ? [
                'tmdb_id' => 401, 'imdb_id' => null, 'title' => 'Movie Only', 'original_title' => 'Movie Only',
                'overview' => 'Synthetic movie.', 'poster_url' => 'https://image.tmdb.org/t/p/w500/movie.jpg', 'backdrop_url' => null,
                'release_date' => null, 'genres' => '', 'vote_average' => null, 'vote_count' => null, 'runtime' => null,
                'status' => null, 'cast' => [], 'director' => [], 'youtube_trailer' => null,
            ] : null;
        }

        private function candidate(int $id, string $name): array
        {
            return ['tmdb_id' => $id, 'name' => $name, 'original_name' => $name, 'first_air_date' => '2020-01-01', 'overview' => 'Synthetic series.'];
        }

        private function tvDetails(int $id, string $name): array
        {
            return [
                'tmdb_id' => $id, 'tvdb_id' => null, 'imdb_id' => null, 'name' => $name, 'original_name' => $name,
                'overview' => 'Synthetic series.', 'poster_url' => "https://image.tmdb.org/t/p/original/details-{$id}.jpg", 'backdrop_url' => null,
                'first_air_date' => null, 'genres' => '', 'vote_average' => null, 'vote_count' => null, 'status' => null,
                'number_of_seasons' => null, 'number_of_episodes' => null, 'cast' => null, 'director' => null, 'youtube_trailer' => null,
            ];
        }
    }

    class EpgCacheEnrichmentService
    {
        public array $records;
        public array $guardedSnapshots = [];
        public array $guardedApplies = [];
        public array $snapshots = [];
        public array $applies = [];
        public int $generation = 1;
        public int $pageSize = 1;
        public ?int $mutateBeforeGuardedSnapshot = null;
        public ?int $mutateBeforeGuardedApply = null;
        public ?int $forceUnsupportedGuardedSnapshotAt = null;
        public ?string $forcedGuardedSnapshotStatus = null;
        public ?string $forcedGuardedApplyStatus = null;
        public bool $omitEvidence = false;
        public bool $omitApplyEvidence = false;

        public function __construct(array $records) { $this->records = array_column($records, null, 'id'); }

        public function snapshot(object $context, object $epg, int $afterId = 0, int $limit = 500): array
        {
            $this->snapshots[] = compact('afterId', 'limit');
            return $this->page($afterId);
        }

        public function guardedSnapshot(object $context, object $epg, int $afterId = 0, int $limit = 500, ?string $evidence = null): array
        {
            $call = count($this->guardedSnapshots) + 1;
            if ($this->mutateBeforeGuardedSnapshot === $call) { $this->generation++; }
            $this->guardedSnapshots[] = compact('afterId', 'limit', 'evidence');
            if ($this->forceUnsupportedGuardedSnapshotAt === $call) { return ['status' => 'unsupported']; }
            if ($this->forcedGuardedSnapshotStatus !== null) { return ['status' => $this->forcedGuardedSnapshotStatus]; }
            if ($evidence !== null && $evidence !== $this->token()) { return ['status' => 'stale']; }
            $page = $this->page($afterId);
            return $this->omitEvidence ? $page : $page + ['evidence' => $this->token()];
        }

        public function apply(object $context, object $epg, array $patches): array
        {
            $this->applies[] = compact('patches');
            $this->applyPatches($patches);
            return ['status' => 'applied'];
        }

        public function guardedApply(object $context, object $epg, array $patches, string $evidence): array
        {
            $call = count($this->guardedApplies) + 1;
            if ($this->mutateBeforeGuardedApply === $call) { $this->generation++; }
            $this->guardedApplies[] = compact('patches', 'evidence');
            if ($this->forcedGuardedApplyStatus !== null) { return ['status' => $this->forcedGuardedApplyStatus]; }
            if ($evidence !== $this->token()) { return ['status' => 'stale']; }
            $this->applyPatches($patches);
            $this->generation++;
            $evidenceNeutral = array_all($patches, static function (array $patch): bool {
                return array_diff(array_keys($patch['changes'] ?? []), ['icon', 'images']) === [];
            });
            return $this->omitApplyEvidence || ! $evidenceNeutral
                ? ['status' => 'applied']
                : ['status' => 'applied', 'evidence' => $this->token()];
        }

        private function token(): string { return 'source-1:g'.$this->generation; }

        private function page(int $afterId): array
        {
            $eligible = array_values(array_filter($this->records, fn (array $row): bool => $row['id'] > $afterId));
            usort($eligible, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            $rows = array_slice($eligible, 0, $this->pageSize);
            $next = count($eligible) > count($rows) ? end($rows)['id'] : null;
            return ['status' => 'ok', 'programmes' => $rows, 'next' => $next];
        }

        private function applyPatches(array $patches): void
        {
            foreach ($patches as $patch) {
                $id = (int) $patch['id'];
                if (! isset($this->records[$id]) || $this->records[$id]['hash'] !== $patch['hash']) { continue; }
                $this->records[$id]['programme'] = array_replace($this->records[$id]['programme'], $patch['changes']);
                $this->records[$id]['hash'] = 'applied-'.$id.'-'.$this->generation;
            }
        }
    }

    class LegacyEpgCacheEnrichmentService
    {
        public array $records;
        public array $snapshots = [];
        public array $applies = [];
        public function __construct(array $records) { $this->records = array_column($records, null, 'id'); }
        public function snapshot(object $context, object $epg, int $afterId = 0, int $limit = 500): array
        {
            $this->snapshots[] = compact('afterId', 'limit');
            $eligible = array_values(array_filter($this->records, fn (array $row): bool => $row['id'] > $afterId));
            usort($eligible, fn (array $a, array $b): int => $a['id'] <=> $b['id']);
            $rows = array_slice($eligible, 0, 1);
            return ['status' => 'ok', 'programmes' => $rows, 'next' => count($eligible) > 1 ? end($rows)['id'] : null];
        }
        public function apply(object $context, object $epg, array $patches): array
        {
            $this->applies[] = compact('patches');
            foreach ($patches as $patch) {
                $id = (int) $patch['id'];
                $this->records[$id]['programme'] = array_replace($this->records[$id]['programme'], $patch['changes']);
            }
            return ['status' => 'applied'];
        }
    }
}

namespace Tests {
    require_once __DIR__.'/../Plugin.php';

    use App\Plugins\Support\PluginExecutionContext;
    use App\Services\EpgCacheEnrichmentService;
    use App\Services\EpgCacheService;
    use App\Services\LegacyEpgCacheEnrichmentService;
    use App\Services\TmdbService;
    use App\Settings\GeneralSettings;
    use AppLocalPlugins\EpgEnricher\Plugin;
    use Illuminate\Support\Facades\Http;
    use Illuminate\Support\Facades\Storage;
    use ReflectionMethod;

    function same(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
            exit(1);
        }
    }

    function row(int $id, array $programme): array
    {
        return ['id' => $id, 'hash' => "hash-{$id}", 'programme' => $programme + ['channel' => 'target']];
    }

    function seriesId(string $value = 'gracenote:SH123456780000'): array
    {
        return ['system' => 'm3u-editor:series-id', 'value' => $value];
    }

    function seed(int $id = 2, string $title = 'Atlas', string $subtitle = 'Seed Episode', string $provider = 'gracenote:SH123456780000'): array
    {
        return row($id, [
            'title' => $title,
            'subtitle' => $subtitle,
            'desc' => 'Original seed description',
            'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.1.'], seriesId($provider)],
            'images' => [[
                'url' => 'https://image.tmdb.org/t/p/w500/foreign-identity.jpg', 'type' => 'poster', 'width' => 500,
                'height' => 750, 'orient' => 'P', 'size' => 2, 'source' => 'tmdb', 'scope' => 'programme',
            ]],
        ]);
    }

    function target(int $id = 1, string $provider = 'gracenote:SH123456780000', string $channel = 'target'): array
    {
        return row($id, [
            'channel' => $channel,
            'title' => 'Atlas', 'subtitle' => 'Unmatched Episode', 'desc' => 'Keep target description',
            'content_id' => 'content-keep', 'series_id' => 'series-keep',
            'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.8.'], seriesId($provider)],
            'images' => [[
                'url' => 'https://provider.invalid/trusted-backdrop.jpg', 'type' => 'backdrop', 'width' => 1600,
                'height' => 900, 'orient' => 'L', 'size' => 1, 'source' => 'provider', 'scope' => 'programme',
            ]],
            'icon' => 'https://provider.invalid/trusted-backdrop.jpg',
        ]);
    }

    function execute(array $rows, ?callable $configure = null, ?PluginExecutionContext $context = null, bool $preserveCache = false): array
    {
        Http::$calls = [];
        if (! $preserveCache) { Storage::$files = []; }
        $host = new EpgCacheEnrichmentService($rows);
        $tmdb = new TmdbService();
        $settings = new GeneralSettings();
        $GLOBALS['bindingHostServices'] = [
            EpgCacheService::class => new EpgCacheService(),
            EpgCacheEnrichmentService::class => $host,
            TmdbService::class => $tmdb,
            GeneralSettings::class => $settings,
        ];
        if ($configure !== null) { $configure($host, $tmdb, $settings); }
        $context ??= new PluginExecutionContext();
        $method = new ReflectionMethod(Plugin::class, 'doEnrich');
        $method->setAccessible(true);
        $result = $method->invoke(new Plugin(), 1, [1], $context);
        return [$result, $host, $tmdb, $context];
    }

    function imageUrls(array $programme): array { return array_column($programme['images'] ?? [], 'url'); }

    // Target-before-seed and seed-before-target cross page boundaries and must converge.
    foreach ([[target(1), seed(2)], [seed(1), target(2)]] as $order => $rows) {
        [$result, $host, $tmdb, $context] = execute($rows);
        same(true, $result->success, 'A guarded fresh binding run must succeed.');
        $targetId = $order === 0 ? 1 : 2;
        $programme = $host->records[$targetId]['programme'];
        same(true, in_array('https://image.tmdb.org/t/p/w500/selected-101.jpg', imageUrls($programme), true), 'The target must receive artwork from the selected identity image response.');
        same(false, in_array('https://image.tmdb.org/t/p/w500/foreign-identity.jpg', imageUrls($programme), true), 'Pre-existing seed artwork from a different identity must never propagate.');
        $selected = array_values(array_filter($programme['images'], fn (array $image): bool => ($image['url'] ?? null) === 'https://image.tmdb.org/t/p/w500/selected-101.jpg'))[0] ?? [];
        same([500, 820], [$selected['width'] ?? null, $selected['height'] ?? null], 'Selected image-response geometry must retain its non-standard aspect instead of a fixed 500x750 fallback.');
        same(['Unmatched Episode', 'Keep target description', 'content-keep', 'series-keep'], [$programme['subtitle'], $programme['desc'], $programme['content_id'], $programme['series_id']], 'Binding must preserve target episode, description, content, and grouping fields.');
        same('https://provider.invalid/trusted-backdrop.jpg', $programme['icon'], 'Binding must preserve independently trusted target artwork.');
        same(true, count($host->guardedSnapshots) >= 4, 'Both bounded passes must use guarded snapshots when a binding exists.');
        same(true, count($context->messages) >= 4, 'Both passes must emit visible heartbeats.');
    }

    // Normal metadata writes consume evidence in Core. Binding artwork must therefore
    // finish in evidence-neutral guarded batches before those writes begin.
    $normalContext = new PluginExecutionContext();
    $normalContext->settings['enrich_descriptions'] = true;
    [$normalResult, $normalHost] = execute([seed(1), target(2)], context: $normalContext);
    same(true, $normalResult->success, 'Normal metadata enrichment alongside a binding must succeed.');
    same(true, in_array('https://image.tmdb.org/t/p/w500/selected-101.jpg', imageUrls($normalHost->records[2]['programme']), true), 'A later target must retain safe reuse after an earlier normal metadata write consumes evidence.');
    same(true, count($normalHost->applies) >= 1, 'Evidence-changing metadata must use the ordinary conditional apply path after binding artwork is complete.');
    foreach ($normalHost->guardedApplies as $guardedApply) {
        foreach ($guardedApply['patches'] as $patch) {
            same([], array_values(array_diff(array_keys($patch['changes']), ['icon', 'images'])), 'Guarded successor-token batches must contain artwork changes only.');
        }
    }

    // Unknown, square, or internally conflicting response geometry cannot become reusable portrait evidence.
    foreach ([
        'unknown' => ['file_path' => '/unknown.jpg', 'iso_639_1' => 'de'],
        'square' => ['file_path' => '/square.jpg', 'width' => 1000, 'height' => 1000, 'iso_639_1' => 'de'],
        'conflicting' => ['file_path' => '/conflicting.jpg', 'width' => 1000, 'height' => 1000, 'aspect_ratio' => 0.6, 'iso_639_1' => 'de'],
    ] as $label => $image) {
        Http::$posterOverrides = [101 => $image];
        [$geometryResult, $geometryHost] = execute([target(1), seed(2)]);
        same(true, $geometryResult->success, "{$label} geometry must abstain without failing ordinary enrichment.");
        $selectedUrl = 'https://image.tmdb.org/t/p/w500/'.ltrim($image['file_path'], '/');
        same(false, in_array($selectedUrl, imageUrls($geometryHost->records[1]['programme']), true), "{$label} geometry must not propagate to the target.");
    }
    Http::$posterOverrides = [];

    // A late fresh conflict poisons the key before any target propagation.
    [$conflictResult, $conflictHost] = execute([
        target(1, 'gracenote:SH999999990000'),
        seed(2, 'Conflict One', 'Conflict A', 'gracenote:SH999999990000'),
        seed(3, 'Conflict Two', 'Conflict B', 'gracenote:SH999999990000'),
    ]);
    same(true, $conflictResult->success, 'A conflict must abstain rather than fail the whole run.');
    same(false, in_array('https://image.tmdb.org/t/p/w500/selected-201.jpg', imageUrls($conflictHost->records[1]['programme']), true), 'A late conflicting identity must prevent earlier cross-row propagation.');
    same([], $conflictHost->guardedApplies, 'A poisoned binding must not use the evidence-bearing apply path.');

    // A direct title winner, wrong media type, and malformed provider identities cannot seed.
    foreach ([
        row(2, ['title' => 'Direct Show', 'subtitle' => 'Direct Episode', 'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.1.'], seriesId()]]),
        row(2, ['title' => 'Movie Only', 'episode_nums' => [seriesId()]]),
        seed(2, provider: 'other:SH123456780000'),
        row(2, [
            'title' => 'Atlas', 'subtitle' => 'Seed Episode',
            'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.1.'], seriesId(), seriesId()],
        ]),
    ] as $nonSeed) {
        [$nonSeedResult, $nonSeedHost] = execute([target(1), $nonSeed]);
        same(true, $nonSeedResult->success, 'A non-seed control must retain ordinary per-row enrichment.');
        same(false, in_array('https://image.tmdb.org/t/p/w500/selected-101.jpg', imageUrls($nonSeedHost->records[1]['programme']), true), 'Direct, non-TV, or untrusted results must not seed a provider binding.');
    }

    // Scope and invocation state are isolated.
    [$scopeResult, $scopeHost] = execute([target(1, channel: 'off-scope'), seed(2)]);
    same(true, $scopeResult->success, 'Off-scope rows must not break a selected run.');
    same(false, in_array('https://image.tmdb.org/t/p/w500/selected-101.jpg', imageUrls($scopeHost->records[1]['programme']), true), 'Off-playlist targets must remain unchanged.');
    [$isolatedResult, $isolatedHost] = execute([target(1)]);
    same(true, $isolatedResult->success, 'A later invocation without a seed must complete safely.');
    same(false, in_array('https://image.tmdb.org/t/p/w500/selected-101.jpg', imageUrls($isolatedHost->records[1]['programme']), true), 'Bindings must not leak across invocations.');

    $legacyHost = new LegacyEpgCacheEnrichmentService([target(1), seed(2)]);
    $GLOBALS['bindingHostServices'] = [
        EpgCacheService::class => new EpgCacheService(), EpgCacheEnrichmentService::class => $legacyHost,
        TmdbService::class => new TmdbService(), GeneralSettings::class => new GeneralSettings(),
    ];
    Storage::$files = [];
    $legacyMethod = new ReflectionMethod(Plugin::class, 'doEnrich');
    $legacyMethod->setAccessible(true);
    $legacyResult = $legacyMethod->invoke(new Plugin(), 1, [1], new PluginExecutionContext());
    same(true, $legacyResult->success, 'A host without the additive guard must retain safe ordinary per-row enrichment.');
    same(false, in_array('https://image.tmdb.org/t/p/w500/selected-101.jpg', imageUrls($legacyHost->records[1]['programme']), true), 'An old host must fail closed for cross-row reuse.');

    // A persisted cache entry is not provenance, but a later invocation must be able to
    // perform a fresh candidate/episode/image validation and establish new provenance.
    execute([seed(1)]);
    [$cachedResult, $cachedHost, $cachedTmdb] = execute([target(1), seed(2)], preserveCache: true);
    same(true, $cachedResult->success, 'Cached per-row enrichment must remain usable.');
    same(true, in_array('https://image.tmdb.org/t/p/w500/selected-101.jpg', imageUrls($cachedHost->records[1]['programme']), true), 'A warm-cache invocation must establish a binding only after fresh revalidation.');
    same(true, in_array(['tv-candidates', 'Atlas'], $cachedTmdb->calls, true), 'Warm-cache binding provenance must include a fresh candidate lookup.');
    same(true, in_array(['season', 101, 1], $cachedTmdb->calls, true), 'Warm-cache binding provenance must include fresh episode validation.');
    same(true, count(Http::$calls) >= 1, 'Warm-cache binding provenance must include a fresh selected-identity image response.');

    // Guard failures fail closed without a legacy retry or token laundering.
    [$busyResult, $busyHost] = execute([target(1), seed(2)], function (EpgCacheEnrichmentService $host): void { $host->forcedGuardedSnapshotStatus = 'busy'; });
    same(false, $busyResult->success, 'A busy guarded snapshot must fail closed.');
    same([], $busyHost->applies, 'A busy guard must not fall back to ordinary apply.');

    [$unsupportedResult, $unsupportedHost] = execute([target(1), seed(2)], function (EpgCacheEnrichmentService $host): void { $host->forcedGuardedSnapshotStatus = 'unsupported'; });
    same(true, $unsupportedResult->success, 'Initial unsupported capability must retain ordinary per-row enrichment.');
    same([], $unsupportedHost->guardedApplies, 'An unsupported host must fail closed for cross-row reuse.');
    same(false, in_array('https://image.tmdb.org/t/p/w500/selected-101.jpg', imageUrls($unsupportedHost->records[1]['programme']), true), 'Unsupported capability must not propagate a provider binding.');

    [$midUnsupportedResult, $midUnsupportedHost] = execute([target(1), seed(2)], function (EpgCacheEnrichmentService $host): void { $host->forceUnsupportedGuardedSnapshotAt = 2; });
    same(false, $midUnsupportedResult->success, 'Unsupported capability after the census starts must fail closed.');
    same([], $midUnsupportedHost->guardedApplies, 'Mid-census unsupported capability must not apply or fall back.');
    [$missingTokenResult, $missingTokenHost] = execute([target(1), seed(2)], function (EpgCacheEnrichmentService $host): void { $host->omitEvidence = true; });
    same(false, $missingTokenResult->success, 'A guarded snapshot without a token must fail closed.');
    same([], $missingTokenHost->applies, 'A missing token must not reach apply.');

    [$driftResult, $driftHost] = execute([target(1), seed(2)], function (EpgCacheEnrichmentService $host): void { $host->mutateBeforeGuardedSnapshot = 3; });
    same(false, $driftResult->success, 'Drift between the evidence pass and apply traversal must fail closed.');
    same([], $driftHost->guardedApplies, 'Snapshot drift must prevent guarded apply.');

    [$lateStaleResult, $lateStaleHost] = execute([target(1), target(2), seed(3)], function (EpgCacheEnrichmentService $host): void { $host->mutateBeforeGuardedApply = 2; });
    same(false, $lateStaleResult->success, 'A later guarded apply becoming stale must fail without retrying against refreshed evidence.');
    same(2, count($lateStaleHost->guardedApplies), 'The stale guarded page must be attempted once only.');
    same([], $lateStaleHost->applies, 'A guarded stale result must never fall back to unguarded apply.');
    same('source-1:g2', $lateStaleHost->guardedSnapshots[4]['evidence'] ?? null, 'An artwork-only accepted batch must roll its evidence-neutral successor token into the next page.');

    [$consumedResult, $consumedHost] = execute([target(1), seed(2)], function (EpgCacheEnrichmentService $host): void { $host->omitApplyEvidence = true; });
    same(true, $consumedResult->success, 'An accepted evidence-changing batch must continue safe ordinary per-row enrichment.');
    same(1, count($consumedHost->guardedApplies), 'A consumed evidence token must never be reused on a later page.');
    same(1, count($consumedHost->applies), 'After token consumption, later pages must use ordinary conditional apply without cross-row reuse.');

    $cancelContext = new PluginExecutionContext();
    $cancelContext->cancelAfterChecks = 3;
    [$cancelResult, $cancelHost, $cancelTmdb, $cancelledContext] = execute([target(1), seed(2)], context: $cancelContext);
    same('cancelled', $cancelResult->status, 'Cancellation during the evidence pass must remain visible.');
    same([], $cancelHost->applies, 'Cancellation must not mutate host-owned data.');
    same(true, count($cancelledContext->messages) >= 1, 'The evidence pass must heartbeat before cancellation ends a longer traversal.');

    echo "Provider series binding host tests passed.\n";
}
