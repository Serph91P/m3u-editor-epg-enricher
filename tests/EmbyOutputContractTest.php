<?php

namespace App\Plugins\Contracts {
    interface EpgProcessorPluginInterface {}
    interface HookablePluginInterface {}

    interface PluginSelectOptionsProviderInterface
    {
        public function selectOptions(string $provider, \App\Plugins\Support\PluginSelectOptionsContext $context): array;
    }
}

namespace App\Plugins\Support {
    class PluginActionResult {}
    class PluginSelectOptionsContext {}

    class PluginExecutionContext
    {
        public function __construct(public readonly bool $dryRun) {}
    }
}

namespace App\Models {
    class FakeCollection
    {
        public function __construct(private array $values) {}

        public function all(): array
        {
            return $this->values;
        }
    }

    class EpgChannelConstraintQuery
    {
        public function whereIn(string $column, array $values): self
        {
            if ($column === 'epg_id') {
                ChannelQuery::$epgIds = array_map('intval', $values);
            }

            return $this;
        }
    }

    class ChannelQuery
    {
        public static array $epgIds = [];
        private array $rows;

        public function __construct()
        {
            $this->rows = Channel::$rows;
            self::$epgIds = [];
        }

        public function whereIn(string $column, array $values): self
        {
            $this->rows = array_values(array_filter(
                $this->rows,
                fn (array $row): bool => in_array($row[$column] ?? null, $values, true),
            ));

            return $this;
        }

        public function where(string $column, mixed $value): self
        {
            $this->rows = array_values(array_filter(
                $this->rows,
                fn (array $row): bool => ($row[$column] ?? null) === $value,
            ));

            return $this;
        }

        public function whereNotNull(string $column): self
        {
            $this->rows = array_values(array_filter(
                $this->rows,
                fn (array $row): bool => ($row[$column] ?? null) !== null,
            ));

            return $this;
        }

        public function whereHas(string $relation, callable $callback): self
        {
            $callback(new EpgChannelConstraintQuery());
            $this->rows = array_values(array_filter(
                $this->rows,
                fn (array $row): bool => in_array($row['epg_id'], self::$epgIds, true),
            ));

            return $this;
        }

        public function distinct(): self
        {
            return $this;
        }

        public function pluck(string $column): FakeCollection
        {
            return new FakeCollection(array_values(array_unique(array_column($this->rows, $column))));
        }
    }

    class PlaylistQuery
    {
        private array $rows;

        public function __construct()
        {
            $this->rows = Playlist::$rows;
        }

        public function whereKey(array $ids): self
        {
            $this->rows = array_values(array_filter(
                $this->rows,
                fn (Playlist $playlist): bool => in_array($playlist->id, $ids, true),
            ));

            return $this;
        }

        public function get(): array
        {
            return $this->rows;
        }
    }

    class Channel
    {
        public static array $rows = [];

        public static function query(): ChannelQuery
        {
            return new ChannelQuery();
        }
    }

    class Playlist
    {
        public static array $rows = [];

        public function __construct(public int $id, public string $name) {}

        public static function query(): PlaylistQuery
        {
            return new PlaylistQuery();
        }

        public function getTable(): string
        {
            return 'playlists';
        }
    }

    class Epg {}
    class EpgChannel {}
}

namespace App\Services {
    use App\Models\Playlist;
    use Illuminate\Support\Facades\Storage;

    class EpgCacheService
    {
        public static array $clearCalls = [];
        public static array $latestXmlByPlaylist = [];

        public static function getPlaylistEpgCachePath(Playlist $playlist, bool $compressed = false): string
        {
            return 'playlist-epg-files/playlists-'.$playlist->id.'-epg.xml'.($compressed ? '.gz' : '');
        }

        public static function clearPlaylistEpgCacheFile(Playlist $playlist): bool
        {
            self::$clearCalls[] = $playlist->id;
            $disk = Storage::disk('local');
            $cleared = false;
            foreach ([false, true] as $compressed) {
                $path = self::getPlaylistEpgCachePath($playlist, $compressed);
                if ($disk->exists($path)) {
                    $disk->delete($path);
                    $cleared = true;
                }
            }

            return $cleared;
        }

        public static function requestPlaylistXmltv(Playlist $playlist): array
        {
            $disk = Storage::disk('local');
            $path = self::getPlaylistEpgCachePath($playlist);
            if ($disk->exists($path)) {
                return ['hit' => true, 'body' => $disk->get($path)];
            }

            $body = self::$latestXmlByPlaylist[$playlist->id];
            $disk->put($path, $body);
            $disk->put(self::getPlaylistEpgCachePath($playlist, true), 'gzip:'.$body);

            return ['hit' => false, 'body' => $body];
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
        public static array $files = [];

        public static function disk(string $name): self
        {
            return new self();
        }

        public function exists(string $path): bool
        {
            return array_key_exists($path, self::$files);
        }

        public function delete(string $path): bool
        {
            unset(self::$files[$path]);

            return true;
        }

        public function get(string $path): string
        {
            return self::$files[$path];
        }

        public function put(string $path, string $contents): void
        {
            self::$files[$path] = $contents;
        }
    }

    class Http {}
    class Log {}
}

namespace Tests {
    require_once __DIR__.'/../Plugin.php';

    use App\Models\Channel;
    use App\Models\Playlist;
    use App\Plugins\Support\PluginExecutionContext;
    use App\Services\EpgCacheService;
    use AppLocalPlugins\EpgEnricher\Plugin;
    use Illuminate\Support\Facades\Storage;
    use ReflectionClass;

    function assertSameValue(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
            exit(1);
        }
    }

    $first = new Playlist(10, 'First selected');
    $second = new Playlist(11, 'Second selected');
    $unaffected = new Playlist(12, 'Unaffected selected');
    $unselected = new Playlist(13, 'Unselected');
    Playlist::$rows = [$first, $second, $unaffected, $unselected];
    Channel::$rows = [
        ['playlist_id' => 10, 'enabled' => true, 'epg_channel_id' => 101, 'epg_id' => 1],
        ['playlist_id' => 10, 'enabled' => true, 'epg_channel_id' => 102, 'epg_id' => 2],
        ['playlist_id' => 11, 'enabled' => true, 'epg_channel_id' => 103, 'epg_id' => 2],
        ['playlist_id' => 12, 'enabled' => true, 'epg_channel_id' => 104, 'epg_id' => 3],
        ['playlist_id' => 13, 'enabled' => true, 'epg_channel_id' => 105, 'epg_id' => 1],
    ];

    foreach (Playlist::$rows as $playlist) {
        Storage::$files[EpgCacheService::getPlaylistEpgCachePath($playlist)] = '<category>Soap</category>';
        Storage::$files[EpgCacheService::getPlaylistEpgCachePath($playlist, true)] = 'gzip:<category>Soap</category>';
        EpgCacheService::$latestXmlByPlaylist[$playlist->id] = '<category>Series</category>';
    }

    $plugin = new Plugin();
    $reflection = new ReflectionClass($plugin);
    $invalidate = $reflection->getMethod('invalidatePlaylistEpgCaches');
    $invalidate->setAccessible(true);
    $invalidate->invoke($plugin, [10, 11, 12], [1, 2], new PluginExecutionContext(false));

    assertSameValue([10, 11], EpgCacheService::$clearCalls, 'Each affected selected playlist must be invalidated exactly once across modified EPG sources.');
    foreach ([$first, $second] as $playlist) {
        $request = EpgCacheService::requestPlaylistXmltv($playlist);
        assertSameValue(false, $request['hit'], 'The first post-enrichment XMLTV request must not remain a stale cache hit.');
        assertSameValue('<category>Series</category>', $request['body'], 'The regenerated XMLTV must expose the enriched category.');
    }
    assertSameValue(true, EpgCacheService::requestPlaylistXmltv($unaffected)['hit'], 'A selected playlist whose EPG did not change must retain its cache.');
    assertSameValue(true, EpgCacheService::requestPlaylistXmltv($unselected)['hit'], 'An unselected playlist must retain its cache.');

    $callsBeforeNoOp = EpgCacheService::$clearCalls;
    $invalidate->invoke($plugin, [10, 11], [], new PluginExecutionContext(false));
    $invalidate->invoke($plugin, [10, 11], [1, 2], new PluginExecutionContext(true));
    assertSameValue($callsBeforeNoOp, EpgCacheService::$clearCalls, 'No-op and dry-run enrichment must not invalidate playlist caches.');

    echo "Emby output contract tests passed.\n";
    echo json_encode([
        'selected_playlist_invalidations' => EpgCacheService::$clearCalls,
        'post_enrichment_stale_hits' => 0,
        'fixture_network_calls' => 0,
    ], JSON_THROW_ON_ERROR)."\n";
}
