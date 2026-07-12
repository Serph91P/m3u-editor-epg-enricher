<?php

namespace {
    function app(string $class): object
    {
        return new App\Services\EpgCacheService();
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
        public function __construct(public bool $success, public string $message, public array $data = []) {}

        public static function success(string $message, array $data = []): self
        {
            return new self(true, $message, $data);
        }

        public static function failure(string $message, array $data = []): self
        {
            return new self(false, $message, $data);
        }
    }

    class PluginExecutionContext
    {
        public array $settings = [
            'auto_run_on_cache' => true,
            'enrich_from_tmdb' => false,
        ];
        public object $user;

        public function __construct()
        {
            $this->user = new \App\Models\User(1);
        }

        public function heartbeat(string $message, ?int $progress = null): void {}
    }
}

namespace App\Models {
    class User
    {
        public function __construct(public int $id) {}

        public function getKey(): int
        {
            return $this->id;
        }
    }

    class FakeCollection
    {
        public function __construct(private array $values) {}

        public function all(): array
        {
            return $this->values;
        }

        public function filter(): self
        {
            return new self(array_values(array_filter($this->values)));
        }

        public function unique(): self
        {
            return new self(array_values(array_unique($this->values)));
        }

        public function values(): self
        {
            return $this;
        }
    }

    class FakeQuery
    {
        private array $playlistIds = [10];

        public function __construct(private ?string $model = null) {}

        public function whereIn(string $column, mixed $values): self
        {
            if ($column === 'playlist_id') {
                $this->playlistIds = (array) $values;
            }

            return $this;
        }

        public function whereKey(int|array $ids): self
        {
            $this->playlistIds = (array) $ids;

            return $this;
        }

        public function __call(string $name, array $arguments): self
        {
            return $this;
        }

        public function pluck(string $column, ?string $key = null): FakeCollection
        {
            if ($this->model === Playlist::class) {
                $values = $column === 'name'
                    ? array_fill_keys($this->playlistIds, 'Fixture playlist')
                    : $this->playlistIds;

                return new FakeCollection($values);
            }

            if ($this->model === Channel::class && $column === 'epg_channels.epg_id') {
                return new FakeCollection(array_map(
                    fn (int $playlistId): int => $playlistId === 20 ? 2 : 1,
                    $this->playlistIds,
                ));
            }

            return new FakeCollection([1]);
        }

        public function count(): int
        {
            return 1;
        }

        public function first(): Playlist
        {
            return new Playlist((int) $this->playlistIds[0]);
        }
    }

    class Channel
    {
        public static function query(): FakeQuery
        {
            return new FakeQuery(self::class);
        }
    }

    class Epg
    {
        public string $name = 'Fixture EPG';

        public static function find(int $id): ?self
        {
            if ($id === 3) {
                throw new \RuntimeException('Fixture exception');
            }

            return $id === 4 ? new self() : null;
        }
    }

    class EpgChannel
    {
        public static function query(): FakeQuery
        {
            return new FakeQuery(self::class);
        }
    }

    class Playlist
    {
        public int $id;
        public string $name = 'Fixture playlist';

        public function __construct(int $id = 10)
        {
            $this->id = $id;
        }

        public static function query(): FakeQuery
        {
            return new FakeQuery(self::class);
        }
    }
}

namespace App\Services {
    class EpgCacheService
    {
        public function isCacheValid(object $epg): bool
        {
            return true;
        }
    }
    class TmdbService {}
}

namespace App\Settings {
    class GeneralSettings {}
}

namespace Illuminate\Support\Facades {
    class Storage
    {
        public static string $root;

        public static function disk(string $name): self
        {
            return new self();
        }

        public function makeDirectory(string $path): void
        {
            if (! is_dir($this->path($path))) {
                mkdir($this->path($path), 0777, true);
            }
        }

        public function path(string $path): string
        {
            return self::$root.'/'.$path;
        }
    }

    class Http {}
    class Log {}
}

namespace Tests {
    require_once __DIR__.'/../Plugin.php';

    use App\Plugins\Support\PluginExecutionContext;
    use AppLocalPlugins\EpgEnricher\Plugin;
    use Illuminate\Support\Facades\Storage;
    use ReflectionMethod;

    function assertTrueValue(bool $condition, string $message): void
    {
        if (! $condition) {
            fwrite(STDERR, $message."\n");
            exit(1);
        }
    }

    function openLockedEpg(int $epgId)
    {
        $path = Storage::$root."/plugin-data/epg-enricher/epg-{$epgId}.lock";
        $handle = fopen($path, 'c');
        assertTrueValue($handle !== false && flock($handle, LOCK_EX | LOCK_NB), "Could not lock EPG {$epgId} fixture.");

        return $handle;
    }

    $tempDir = sys_get_temp_dir().'/epg-enricher-lock-'.bin2hex(random_bytes(6));
    mkdir($tempDir.'/plugin-data/epg-enricher', 0777, true);
    Storage::$root = $tempDir;

    $plugin = new Plugin();
    $context = new PluginExecutionContext();
    $epgOneLock = openLockedEpg(1);

    $hookResult = $plugin->runHook('epg.cache.generated', [
        'epg_id' => 1,
        'user_id' => 1,
        'playlist_ids' => [10],
    ], $context);
    assertTrueValue($hookResult->success, 'A competing hook run should skip successfully.');
    assertTrueValue(str_contains(strtolower($hookResult->message), 'already in progress'), 'The hook busy result should clearly explain the skip.');

    $manualResult = $plugin->runAction('enrich_epg', ['playlist_id' => 10], $context);
    assertTrueValue($manualResult->success, 'A competing manual run should skip successfully.');
    assertTrueValue(str_contains(strtolower($manualResult->message), 'already in progress'), 'The manual busy result should clearly explain the skip.');

    $differentEpgResult = $plugin->runHook('epg.cache.generated', [
        'epg_id' => 2,
        'user_id' => 1,
        'playlist_ids' => [20],
    ], $context);
    assertTrueValue(! $differentEpgResult->success, 'A lock for one EPG must not skip a different EPG.');

    flock($epgOneLock, LOCK_UN);
    fclose($epgOneLock);

    $method = new ReflectionMethod($plugin, 'doEnrich');
    $method->setAccessible(true);
    try {
        $method->invoke($plugin, 3, [10], $context);
        assertTrueValue(false, 'The exception fixture should throw.');
    } catch (\RuntimeException $exception) {
        assertTrueValue($exception->getMessage() === 'Fixture exception', 'The fixture exception should propagate.');
    }

    $releasedExceptionLock = openLockedEpg(3);
    flock($releasedExceptionLock, LOCK_UN);
    fclose($releasedExceptionLock);

    $successResult = $method->invoke($plugin, 4, [10], $context);
    assertTrueValue($successResult->success, 'The success fixture should complete normally.');
    $releasedSuccessLock = openLockedEpg(4);
    flock($releasedSuccessLock, LOCK_UN);
    fclose($releasedSuccessLock);

    foreach (glob($tempDir.'/plugin-data/epg-enricher/*') as $file) {
        unlink($file);
    }
    rmdir($tempDir.'/plugin-data/epg-enricher');
    rmdir($tempDir.'/plugin-data');
    rmdir($tempDir);

    echo "Concurrent enrichment lock tests passed.\n";
}
