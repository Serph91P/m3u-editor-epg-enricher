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
            'auto_run_playlists' => [10, 20, 999],
            'enrich_from_tmdb' => false,
        ];

        public function __construct(public ?object $user) {}

        public function heartbeat(string $message, ?int $progress = null): void {}
    }

    class PluginSelectOptionsContext
    {
        public function __construct(public ?object $user) {}
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

        public function all(): array
        {
            return $this->values;
        }
    }

    class ChannelConstraintQuery
    {
        public function where(string $column, mixed $value): self
        {
            if ($column === 'enabled' && $value === true) {
                Playlist::$eligibilityConstraints['enabled'] = true;
            }

            return $this;
        }

        public function whereNotNull(string $column): self
        {
            if ($column === 'epg_channel_id') {
                Playlist::$eligibilityConstraints['epg_channel_id'] = true;
            }

            return $this;
        }

        public function whereHas(string $relation): self
        {
            if ($relation === 'epgChannel') {
                Playlist::$eligibilityConstraints['epgChannel'] = true;
            }

            return $this;
        }
    }

    class PlaylistQuery
    {
        private array $rows;

        public function __construct()
        {
            $this->rows = Playlist::$rows;
        }

        public function where(string $column, mixed $value): self
        {
            $this->rows = array_values(array_filter(
                $this->rows,
                fn (object $row): bool => $row->{$column} === $value,
            ));

            return $this;
        }

        public function whereHas(string $relation, callable $callback): self
        {
            if ($relation === 'channels') {
                $callback(new ChannelConstraintQuery());
                $this->rows = array_values(array_filter($this->rows, fn (object $row): bool => $row->eligible));
            }

            return $this;
        }

        public function whereKey(int|array $ids): self
        {
            $ids = array_map('intval', (array) $ids);
            $this->rows = array_values(array_filter($this->rows, fn (object $row): bool => in_array($row->id, $ids, true)));

            return $this;
        }

        public function orderBy(string $column): self
        {
            usort($this->rows, fn (object $left, object $right): int => $left->{$column} <=> $right->{$column});

            return $this;
        }

        public function pluck(string $value, ?string $key = null): FakeCollection
        {
            $values = [];
            foreach ($this->rows as $row) {
                $key === null ? $values[] = $row->{$value} : $values[$row->{$key}] = $row->{$value};
            }

            return new FakeCollection($values);
        }

        public function first(): ?object
        {
            return $this->rows[0] ?? null;
        }
    }

    class Playlist
    {
        public static array $rows = [];
        public static array $eligibilityConstraints = [];

        public static function query(): PlaylistQuery
        {
            return new PlaylistQuery();
        }
    }

    class ChannelQuery
    {
        private array $rows;

        public function __construct()
        {
            $this->rows = Channel::$rows;
        }

        public function whereIn(string $column, mixed $values): self
        {
            if ($column === 'playlist_id') {
                Channel::$resolvedPlaylistIds = $values;
            }

            $values = (array) $values;
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

        public function whereHas(string $relation, ?callable $callback = null): self
        {
            return $this;
        }

        public function join(string ...$arguments): self
        {
            return $this;
        }

        public function distinct(): self
        {
            return $this;
        }

        public function count(): int
        {
            return count($this->rows);
        }

        public function pluck(string $column): FakeCollection
        {
            return new FakeCollection(array_values(array_unique(array_map(
                fn (array $row): mixed => $column === 'epg_channels.epg_id'
                    ? $row['epg_id']
                    : ($row[$column] ?? null),
                $this->rows,
            ))));
        }
    }

    class EpgChannelQuery
    {
        private array $rows;
        private ?int $epgId = null;

        public function __construct()
        {
            $this->rows = EpgChannel::$rows;
        }

        public function where(string $column, mixed $value): self
        {
            if ($column === 'epg_id') {
                $this->epgId = (int) $value;
            }

            $this->rows = array_values(array_filter(
                $this->rows,
                fn (array $row): bool => $row[$column] === $value,
            ));

            return $this;
        }

        public function whereIn(string $column, mixed $values): self
        {
            $values = is_object($values) && method_exists($values, 'all') ? $values->all() : (array) $values;
            $this->rows = array_values(array_filter(
                $this->rows,
                fn (array $row): bool => in_array($row[$column], $values, true),
            ));

            return $this;
        }

        public function pluck(string $column): FakeCollection
        {
            if ($column === 'channel_id' && $this->epgId !== null) {
                EpgChannel::$resolvedChannels[$this->epgId] = array_column($this->rows, 'id');
            }

            return new FakeCollection(array_column($this->rows, $column));
        }
    }

    class Channel
    {
        public static array $rows = [];
        public static array $resolvedPlaylistIds = [];

        public static function query(): ChannelQuery
        {
            return new ChannelQuery();
        }
    }

    class Epg
    {
        public static array $findIds = [];
        public string $name = 'Fixture EPG';

        public static function find(int $id): self
        {
            self::$findIds[] = $id;

            return new self();
        }
    }

    class EpgChannel
    {
        public static array $rows = [];
        public static array $resolvedChannels = [];

        public static function query(): EpgChannelQuery
        {
            return new EpgChannelQuery();
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

    use App\Models\Channel;
    use App\Models\Epg;
    use App\Models\EpgChannel;
    use App\Models\Playlist;
    use App\Models\User;
    use App\Plugins\Contracts\PluginSelectOptionsProviderInterface;
    use App\Plugins\Support\PluginExecutionContext;
    use App\Plugins\Support\PluginSelectOptionsContext;
    use AppLocalPlugins\EpgEnricher\Plugin;
    use Illuminate\Support\Facades\Storage;

    function assertSameValue(mixed $expected, mixed $actual, string $message): void
    {
        if ($expected !== $actual) {
            fwrite(STDERR, $message."\nExpected: ".var_export($expected, true)."\nActual: ".var_export($actual, true)."\n");
            exit(1);
        }
    }

    function assertTrueValue(bool $condition, string $message): void
    {
        if (! $condition) {
            fwrite(STDERR, $message."\n");
            exit(1);
        }
    }

    $manifest = json_decode(file_get_contents(__DIR__.'/../plugin.json'), true, flags: JSON_THROW_ON_ERROR);
    $autoField = $manifest['settings'][3]['fields'][1];
    $manualField = $manifest['actions'][0]['fields'][0];
    foreach ([$autoField, $manualField] as $field) {
        assertSameValue('select', $field['type'] ?? null, 'Playlist fields must be plugin-provided selects.');
        assertSameValue('enrichable_playlists', $field['options_provider'] ?? null, 'Playlist fields must use the enrichable playlist provider.');
        assertSameValue(false, isset($field['model']), 'Playlist fields must not expose host model selectors.');
    }

    Playlist::$rows = [
        (object) ['id' => 10, 'user_id' => 1, 'name' => 'Zulu source', 'eligible' => true],
        (object) ['id' => 11, 'user_id' => 1, 'name' => 'Alpha source', 'eligible' => true],
        (object) ['id' => 20, 'user_id' => 1, 'name' => 'Empty output', 'eligible' => false],
        (object) ['id' => 30, 'user_id' => 2, 'name' => 'Other user source', 'eligible' => true],
    ];

    $plugin = new Plugin();
    assertTrueValue($plugin instanceof PluginSelectOptionsProviderInterface, 'Plugin must implement the select options provider interface.');
    $options = $plugin->selectOptions('enrichable_playlists', new PluginSelectOptionsContext(new User(1)));
    assertSameValue([11 => 'Alpha source', 10 => 'Zulu source'], $options, 'Provider must return only owned, enrichable playlists in name order.');
    assertSameValue(
        ['enabled' => true, 'epg_channel_id' => true, 'epgChannel' => true],
        Playlist::$eligibilityConstraints,
        'Eligibility must require an enabled channel with an EPG ID and relation.',
    );
    assertSameValue([], $plugin->selectOptions('unknown', new PluginSelectOptionsContext(new User(1))), 'Unknown providers must return no options.');
    assertSameValue([], $plugin->selectOptions('enrichable_playlists', new PluginSelectOptionsContext(null)), 'Anonymous users must receive no options.');

    $manualContext = new PluginExecutionContext(new User(1));
    $notOwned = $plugin->runAction('enrich_epg', ['playlist_id' => 30], $manualContext);
    assertSameValue(false, $notOwned->success, 'Manual runs must reject playlists owned by another user.');
    assertTrueValue(str_contains($notOwned->message, 'not owned or is not enrichable'), 'Manual ownership rejection must be clear.');
    $notEnrichable = $plugin->runAction('enrich_epg', ['playlist_id' => 20], $manualContext);
    assertSameValue(false, $notEnrichable->success, 'Manual runs must reject playlists without eligible channels.');

    $tempDir = sys_get_temp_dir().'/epg-enricher-selection-'.bin2hex(random_bytes(6));
    mkdir($tempDir, 0777, true);
    Storage::$root = $tempDir;
    $hookResult = $plugin->runHook('epg.cache.generated', [
        'epg_id' => 1,
        'user_id' => 1,
        'playlist_ids' => [10, 20, 30, 999],
    ], $manualContext);
    assertSameValue(true, $hookResult->success, 'Hook fixture should stop cleanly after channel resolution.');
    assertSameValue([10], Channel::$resolvedPlaylistIds, 'Hook must pass only owned, enrichable, configured playlist IDs to enrichment.');

    Channel::$resolvedPlaylistIds = [];
    $manualContext->settings['auto_run_playlists'] = ['crafted'];
    $craftedSettingsResult = $plugin->runHook('epg.cache.generated', [
        'epg_id' => 1,
        'user_id' => 1,
        'playlist_ids' => [10],
    ], $manualContext);
    assertSameValue(true, $craftedSettingsResult->success, 'Malformed configured IDs should produce a clean hook skip.');
    assertSameValue([], Channel::$resolvedPlaylistIds, 'Malformed configured IDs must not broaden enrichment to all playlists.');

    Playlist::$rows = [
        (object) ['id' => 10, 'user_id' => 1, 'name' => 'First selected', 'eligible' => true],
        (object) ['id' => 11, 'user_id' => 1, 'name' => 'Second selected', 'eligible' => true],
        (object) ['id' => 12, 'user_id' => 1, 'name' => 'Not selected', 'eligible' => true],
    ];
    Channel::$rows = [
        ['playlist_id' => 10, 'enabled' => true, 'epg_channel_id' => 101, 'epg_id' => 1],
        ['playlist_id' => 10, 'enabled' => true, 'epg_channel_id' => 102, 'epg_id' => 2],
        ['playlist_id' => 10, 'enabled' => true, 'epg_channel_id' => 103, 'epg_id' => 2],
        ['playlist_id' => 11, 'enabled' => true, 'epg_channel_id' => 104, 'epg_id' => 2],
        ['playlist_id' => 12, 'enabled' => true, 'epg_channel_id' => 105, 'epg_id' => 3],
    ];
    EpgChannel::$rows = [
        ['id' => 101, 'epg_id' => 1, 'channel_id' => 'epg-a'],
        ['id' => 102, 'epg_id' => 2, 'channel_id' => 'epg-b-one'],
        ['id' => 103, 'epg_id' => 2, 'channel_id' => 'epg-b-two'],
        ['id' => 104, 'epg_id' => 2, 'channel_id' => 'epg-b-three'],
        ['id' => 105, 'epg_id' => 3, 'channel_id' => 'epg-c'],
    ];
    Epg::$findIds = [];
    EpgChannel::$resolvedChannels = [];
    $manualContext->settings['auto_run_playlists'] = [10, 11];
    $playlistScopedResult = $plugin->runHook('epg.cache.generated', [
        'epg_id' => 1,
        'user_id' => 1,
        'playlist_ids' => [10, 10, 11, 12],
    ], $manualContext);
    assertSameValue(true, $playlistScopedResult->success, 'Playlist-scoped auto-run should complete cleanly.');
    assertTrueValue(
        str_contains($playlistScopedResult->message, "playlists 'First selected', 'Second selected'"),
        'Auto-run summaries must name every selected playlist.',
    );
    assertSameValue([1, 2], Epg::$findIds, 'Auto-run must enrich every distinct EPG referenced by selected playlists, but no unselected playlist EPG.');
    assertSameValue(
        [1 => [101], 2 => [102, 103, 104]],
        EpgChannel::$resolvedChannels,
        'Each EPG must target only channels from selected playlists.',
    );

    foreach (glob($tempDir.'/plugin-data/epg-enricher/*') as $file) {
        unlink($file);
    }
    rmdir($tempDir.'/plugin-data/epg-enricher');
    rmdir($tempDir.'/plugin-data');
    rmdir($tempDir);

    echo "Enrichable playlist selection tests passed.\n";
}
