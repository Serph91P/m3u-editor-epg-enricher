<?php

namespace {
    $hostStoreFixture = '/opt/data/tmp/issue55-upstream-EpgProgrammeStore.php';
    if (! is_file($hostStoreFixture)) {
        throw new \RuntimeException("Required frozen host EpgProgrammeStore fixture is unavailable at {$hostStoreFixture}.");
    }
    require_once $hostStoreFixture;

    function app(string $class): object
    {
        return match ($class) {
            App\Services\EpgCacheService::class => new App\Services\EpgCacheService(),
            App\Settings\GeneralSettings::class => new App\Settings\GeneralSettings(),
            default => $GLOBALS['sqliteTmdb'],
        };
    }

    function now(): object
    {
        return new class
        {
            public function toIso8601String(): string
            {
                return '2026-09-05T12:00:00+00:00';
            }
        };
    }

    function storage_path(string $path = ''): string
    {
        return Illuminate\Support\Facades\Storage::$root.($path === '' ? '' : '/'.$path);
    }
}

namespace App\Plugins\Contracts {
    interface EpgProcessorPluginInterface {}
    interface HookablePluginInterface {}

    interface PluginSelectOptionsProviderInterface
    {
        public function selectOptions(string $provider, \App\Plugins\Support\PluginSelectOptionsContext $context): array;
    }
}

namespace App\Plugins\Support {
    class PluginSelectOptionsContext {}

    class PluginActionResult
    {
        public function __construct(
            public readonly string $status,
            public readonly bool $success,
            public readonly string $summary,
            public readonly array $data = [],
        ) {}

        public static function success(string $summary, array $data = []): self { return new self('completed', true, $summary, $data); }
        public static function failure(string $summary, array $data = []): self { return new self('failed', false, $summary, $data); }
        public static function cancelled(string $summary, array $data = []): self { return new self('cancelled', false, $summary, $data); }
    }

    class PluginExecutionContext
    {
        public array $settings = [
            'enrich_from_tmdb' => true,
            'overwrite_existing' => false,
            'enrich_categories' => true,
            'enrich_descriptions' => true,
            'enrich_posters' => false,
            'enrich_backdrops' => false,
            'map_genres_to_epg_categories' => true,
            'map_genres_to_kodi_guide_genres' => false,
            'keyword_category_detection' => false,
            'enrich_episode_details' => false,
        ];
        public int $cancelAfterChecks = PHP_INT_MAX;
        public int $cancellationChecks = 0;
        public array $messages = [];

        public function cancellationRequested(): bool
        {
            $this->cancellationChecks++;

            return $this->cancellationChecks >= $this->cancelAfterChecks;
        }

        public function heartbeat(string $message, ?int $progress = null): void { $this->messages[] = $message; }
        public function info(string $message): void { $this->messages[] = $message; }
        public function warning(string $message): void { $this->messages[] = $message; }
    }
}

namespace App\Models {
    class FakeCollection
    {
        public function __construct(private array $values) {}
        public function filter(): self { return new self(array_values(array_filter($this->values))); }
        public function unique(): self { return new self(array_values(array_unique($this->values))); }
        public function values(): self { return $this; }
        public function all(): array { return $this->values; }
    }

    class FakeQuery
    {
        public function __construct(private string $model) {}
        public function __call(string $name, array $arguments): self { return $this; }
        public function pluck(string $column): FakeCollection
        {
            return new FakeCollection($this->model === Channel::class ? [1] : ['target']);
        }
    }

    class Channel { public static function query(): FakeQuery { return new FakeQuery(self::class); } }
    class EpgChannel { public static function query(): FakeQuery { return new FakeQuery(self::class); } }
    class Playlist {}

    class Epg
    {
        public string $name = 'SQLite fixture';
        public string $uuid = 'sqlite-fixture';
        public static function find(int $id): self { return new self(); }
    }
}

namespace App\Services {
    class EpgCacheService { public function isCacheValid(object $epg): bool { return true; } }
    class TmdbService
    {
        protected string $language = 'en-US';
        public function isConfigured(): bool { return true; }
    }

    class LocalSqliteTmdbService extends TmdbService
    {
        public bool $sawTransactionDuringLookup = false;

        public function __construct(private ?\PDO $database = null, private ?string $staleData = null) {}

        public function getMovieDetails(int $id): ?array
        {
            $this->sawTransactionDuringLookup = $this->database?->inTransaction() ?? false;
            if ($id === 102 && $this->database !== null && $this->staleData !== null) {
                $this->database->prepare('UPDATE programmes SET data = ? WHERE channel_id = ? AND date = ? AND start_ts = ? AND stop_ts = ?')
                    ->execute([$this->staleData, 'target', '2026-09-05', 1725534000, 1725537600]);
            }

            $title = match ($id) {
                101 => 'Fixture Feature',
                102 => 'Stale Feature',
                103 => 'Refreshed Feature',
                104 => 'Replacement Feature',
                105 => 'Rollback Feature',
                default => 'Refreshed Feature',
            };

            return [
                'tmdb_id' => $id,
                'imdb_id' => null,
                'title' => $title,
                'original_title' => $title,
                'overview' => 'Local fake TMDB overview',
                'poster_url' => null,
                'backdrop_url' => null,
                'release_date' => '2024-01-01',
                'genres' => 'Drama',
                'vote_average' => null,
                'vote_count' => null,
                'runtime' => null,
                'status' => null,
                'cast' => [],
                'director' => [],
                'youtube_trailer' => null,
            ];
        }
    }

    class AtomicReplacingSqliteTmdbService extends LocalSqliteTmdbService
    {
        public bool $replaced = false;

        public function __construct(private string $replacementPath, private string $cachePath) {}

        public function getMovieDetails(int $id): ?array
        {
            if (! $this->replaced) {
                rename($this->replacementPath, $this->cachePath);
                $this->replaced = true;
            }

            return parent::getMovieDetails($id);
        }
    }
}

namespace App\Settings {
    class GeneralSettings { public string $tmdb_language = 'en-US'; public string $tmdb_api_key = ''; }
}

namespace Carbon {
    class Carbon
    {
        private function __construct(private \DateTimeImmutable $date) {}
        public static function parse(string $date): self { return new self(new \DateTimeImmutable($date)); }
        public function diffInDays(self $other): int { return (int) $this->date->diff($other->date)->days; }
        public function lte(self $other): bool { return $this->date <= $other->date; }
        public function format(string $format): string { return $this->date->format($format); }
        public function addDay(): self { $this->date = $this->date->modify('+1 day'); return $this; }
    }
}

namespace Illuminate\Support\Facades {
    class Storage
    {
        public static string $root;
        public static function disk(string $name): self { return new self(); }
        public function path(string $path): string { return self::$root.'/'.$path; }
        public function exists(string $path): bool { return file_exists($this->path($path)); }
        public function get(string $path): string { return (string) file_get_contents($this->path($path)); }
        public function put(string $path, string $contents): bool { return file_put_contents($this->path($path), $contents) !== false; }
        public function delete(string $path): void { @unlink($this->path($path)); }
        public function makeDirectory(string $path): void { if (! is_dir($this->path($path))) { mkdir($this->path($path), 0777, true); } }
    }

    class Http {}
    class Log {}
}

namespace Tests {
    require_once __DIR__.'/../Plugin.php';

    use App\Plugins\Support\PluginExecutionContext;
    use App\Services\EpgProgrammeStore;
    use App\Services\LocalSqliteTmdbService;
    use AppLocalPlugins\EpgEnricher\Plugin;
    use Illuminate\Support\Facades\Storage;
    use PDO;
    use ReflectionMethod;

    function assertSameValue(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
            exit(1);
        }
    }

    function programmeData(int $id, string $title, array $extra = []): string
    {
        return json_encode(array_merge([
            'title' => $title,
            'tmdb_id' => $id,
            'tmdb_media_type' => 'movie',
            'provider_extension' => ['kept' => true],
        ], $extra), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    $tempDir = sys_get_temp_dir().'/epg-enricher-sqlite-'.bin2hex(random_bytes(6));
    $cacheDir = $tempDir.'/epg-cache/sqlite-fixture/v2';
    mkdir($cacheDir, 0777, true);
    Storage::$root = $tempDir;
    file_put_contents($cacheDir.'/metadata.json', json_encode([
        'programme_date_range' => ['min_date' => '2026-09-05', 'max_date' => '2026-09-05'],
    ], JSON_THROW_ON_ERROR));

    $normalData = programmeData(101, 'Fixture Feature', ['unknown_scalar' => 'preserve-me']);
    $nullableStopData = programmeData(103, 'Refreshed Feature');
    $refreshedData = json_encode(['title' => 'Cache refresh won', 'refresh_marker' => true], JSON_THROW_ON_ERROR);
    $unmappedData = json_encode(['title' => 'Unmapped', 'unknown_unmapped' => ['keep' => 'bytes']], JSON_THROW_ON_ERROR);
    $store = new EpgProgrammeStore();
    $store->beginWrite($cacheDir.'/programmes.sqlite');
    $store->insert('target', '2026-09-05', 1725526800, 1725530400, json_decode($normalData, true, 512, JSON_THROW_ON_ERROR));
    $store->insert('target', '2026-09-05', 1725530400, null, json_decode($nullableStopData, true, 512, JSON_THROW_ON_ERROR));
    $store->insert('unmapped', '2026-09-05', 1725526800, 1725530400, json_decode($unmappedData, true, 512, JSON_THROW_ON_ERROR));
    $store->finish();
    $store = EpgProgrammeStore::openRead($cacheDir.'/programmes.sqlite');
    $hostHydratedFixture = $store->read('2026-09-05', ['target']);
    $store->close();
    assertSameValue('2024-09-05T09:00:00.000000Z', $hostHydratedFixture['target'][0]['start'] ?? null, 'The SQLite fixture must be reopened through host hydration.');

    $database = new PDO('sqlite:'.$cacheDir.'/programmes.sqlite');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $insert = $database->prepare('INSERT INTO programmes (channel_id, date, start_ts, stop_ts, data) VALUES (?, ?, ?, ?, ?)');
    $GLOBALS['sqliteTmdb'] = new LocalSqliteTmdbService($database);

    $plugin = new Plugin();
    $method = new ReflectionMethod($plugin, 'doEnrich');
    $method->setAccessible(true);
    $result = $method->invoke($plugin, 1, [1], new PluginExecutionContext());

    assertSameValue(true, $result->success, 'SQLite-only cache data should be processed without a JSONL date file.');
    assertSameValue(2, $result->data['programmes_processed'] ?? null, 'Only targeted SQLite rows should be hydrated and processed.');
    assertSameValue(2, $result->data['programmes_updated'] ?? null, 'Nullable and non-null stop rows should both persist.');
    $normalRow = $database->query("SELECT channel_id, start_ts, stop_ts, data FROM programmes WHERE channel_id = 'target' AND start_ts = 1725526800")->fetch(PDO::FETCH_ASSOC);
    $normal = json_decode($normalRow['data'], true, 512, JSON_THROW_ON_ERROR);
    assertSameValue('Local fake TMDB overview', $normal['desc'] ?? null, 'Targeted SQLite data should receive enrichProgrammeFromTmdb output.');
    assertSameValue('preserve-me', $normal['unknown_scalar'] ?? null, 'Unknown programme fields must survive SQLite dehydration.');
    assertSameValue(['kept' => true], $normal['provider_extension'] ?? null, 'Nested unknown programme fields must survive SQLite dehydration.');
    assertSameValue(false, array_key_exists('channel', $normal), 'Column-owned channel data must not be serialized into SQLite JSON.');
    assertSameValue(false, array_key_exists('start', $normal), 'Column-owned start data must not be serialized into SQLite JSON.');
    assertSameValue(false, array_key_exists('stop', $normal), 'Column-owned stop data must not be serialized into SQLite JSON.');
    $hostHydratedNormal = EpgProgrammeStore::hydrate(
        $normal,
        $normalRow['channel_id'],
        (int) $normalRow['start_ts'],
        (int) $normalRow['stop_ts'],
    );
    assertSameValue('target', $hostHydratedNormal['channel'], 'Host-style hydration must restore the column-owned channel.');
    assertSameValue('2024-09-05T09:00:00.000000Z', $hostHydratedNormal['start'], 'Host-style hydration must restore the column-owned start.');
    assertSameValue('2024-09-05T10:00:00.000000Z', $hostHydratedNormal['stop'], 'Host-style hydration must restore the column-owned stop.');
    $nullableStop = json_decode($database->query("SELECT data FROM programmes WHERE channel_id = 'target' AND start_ts = 1725530400")->fetchColumn(), true, 512, JSON_THROW_ON_ERROR);
    assertSameValue('Local fake TMDB overview', $nullableStop['desc'] ?? null, 'A NULL stop_ts row must be conditionally updated.');
    assertSameValue($unmappedData, $database->query("SELECT data FROM programmes WHERE channel_id = 'unmapped'")->fetchColumn(), 'Unmapped SQLite rows must remain byte-for-byte unchanged.');
    assertSameValue(['programmes'], $database->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN), 'SQLite enrichment must not create or migrate host cache tables.');
    assertSameValue(false, $GLOBALS['sqliteTmdb']->sawTransactionDuringLookup, 'TMDB lookups must finish before the SQLite persistence transaction starts.');

    $rollbackData = programmeData(105, 'Rollback Feature');
    $staleData = programmeData(102, 'Stale Feature');
    $insert->execute(['target', '2026-09-05', 1725523200, 1725526800, $rollbackData]);
    $insert->execute(['target', '2026-09-05', 1725534000, 1725537600, $staleData]);
    $GLOBALS['sqliteTmdb'] = new LocalSqliteTmdbService($database, $refreshedData);
    $conflictResult = $method->invoke($plugin, 1, [1], new PluginExecutionContext());
    assertSameValue(false, $conflictResult->success, 'A conditional SQLite update mismatch must fail the whole persistence operation.');
    assertSameValue(0, $conflictResult->data['programmes_updated'] ?? null, 'A conditional SQLite update mismatch must report zero persisted updates.');
    assertSameValue($rollbackData, $database->query('SELECT data FROM programmes WHERE start_ts = 1725523200')->fetchColumn(), 'A conditional mismatch must roll back earlier SQLite writes.');
    assertSameValue($refreshedData, $database->query('SELECT data FROM programmes WHERE start_ts = 1725534000')->fetchColumn(), 'A refreshed row must not be overwritten with stale reconstructed data.');

    $beforeCancellation = $database->query('SELECT data FROM programmes ORDER BY channel_id, start_ts')->fetchAll(PDO::FETCH_COLUMN);
    $GLOBALS['sqliteTmdb'] = new LocalSqliteTmdbService($database);
    $cancelContext = new PluginExecutionContext();
    $cancelContext->cancelAfterChecks = 2;
    $cancelResult = $method->invoke($plugin, 1, [1], $cancelContext);
    assertSameValue('cancelled', $cancelResult->status, 'SQLite cancellation should propagate to doEnrich.');
    assertSameValue(0, $cancelResult->data['programmes_updated'] ?? null, 'Cancelled SQLite work must not report unpersisted updates.');
    assertSameValue($beforeCancellation, $database->query('SELECT data FROM programmes ORDER BY channel_id, start_ts')->fetchAll(PDO::FETCH_COLUMN), 'Cancellation must leave SQLite programme data unchanged.');

    $atomicReplaceData = programmeData(104, 'Replacement Feature');
    $insert->execute(['target', '2026-09-05', 1725537600, 1725541200, $atomicReplaceData]);
    $replacementPath = $cacheDir.'/replacement.sqlite';
    copy($cacheDir.'/programmes.sqlite', $replacementPath);
    $replacementDatabase = new PDO('sqlite:'.$replacementPath);
    $replacementData = json_encode(['title' => 'Host replacement won', 'replacement_marker' => true], JSON_THROW_ON_ERROR);
    $replacementDatabase->prepare('UPDATE programmes SET data = ? WHERE start_ts = ?')->execute([$replacementData, 1725537600]);
    $replacementDatabase = null;
    $replacementBytes = file_get_contents($replacementPath);
    $GLOBALS['sqliteTmdb'] = new \App\Services\AtomicReplacingSqliteTmdbService($replacementPath, $cacheDir.'/programmes.sqlite');
    $atomicReplaceResult = $method->invoke($plugin, 1, [1], new PluginExecutionContext());
    assertSameValue(false, $atomicReplaceResult->success, 'An atomic host SQLite replacement during lookup must fail persistence.');
    assertSameValue(0, $atomicReplaceResult->data['programmes_updated'] ?? null, 'A replaced SQLite cache must report zero persisted updates.');
    assertSameValue(true, $GLOBALS['sqliteTmdb']->replaced, 'The offline fake TMDB lookup must atomically replace the cache.');
    assertSameValue($replacementBytes, file_get_contents($cacheDir.'/programmes.sqlite'), 'An atomic host replacement database must remain byte-for-byte unchanged.');

    $database = null;
    $database = new PDO('sqlite:'.$cacheDir.'/programmes.sqlite');
    $database->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $database->exec('DROP TABLE programmes');
    $database->exec('CREATE TABLE unexpected_cache_shape (data TEXT NOT NULL)');
    $errorResult = $method->invoke($plugin, 1, [1], new PluginExecutionContext());
    assertSameValue(false, $errorResult->success, 'An incompatible SQLite cache must fail closed.');
    assertSameValue(0, $errorResult->data['programmes_updated'] ?? null, 'A SQLite persistence error must report zero persisted updates.');
    assertSameValue(['unexpected_cache_shape'], $database->query("SELECT name FROM sqlite_master WHERE type = 'table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN), 'An incompatible SQLite cache must not be migrated or replaced.');

    $database = null;
    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($tempDir);

    echo "SQLite programme cache tests passed.\n";
}
