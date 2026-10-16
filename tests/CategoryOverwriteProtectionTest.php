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
    class PluginSelectOptionsContext {}
    class PluginExecutionContext {}
    class PluginActionResult
    {
        public function __construct(
            public readonly string $status,
            public readonly bool $success,
            public readonly string $summary,
            public readonly array $data = [],
        ) {}

        public static function success(string $summary, array $data = []): self
        {
            return new self('completed', true, $summary, $data);
        }

        public static function failure(string $summary, array $data = []): self
        {
            return new self('failed', false, $summary, $data);
        }

        public static function cancelled(string $summary, array $data = []): self
        {
            return new self('cancelled', false, $summary, $data);
        }
    }
}

namespace App\Services {
    class TmdbService
    {
        public function __construct(private readonly bool $match = true) {}

        public function searchTvSeries(string $title, ?int $year = null): ?array
        {
            return $this->match ? [
                'tmdb_id' => 42,
                'name' => $title,
                'first_air_date' => '2024-01-01',
            ] : null;
        }

        public function getTvSeriesDetails(int $tmdbId): ?array
        {
            return $this->match ? [
                'tmdb_id' => $tmdbId,
                'name' => 'Category Fixture',
                'genres' => 'Comedy',
                'overview' => '',
            ] : null;
        }

        public function getTvAlternativeTitles(int $tmdbId): array
        {
            return [];
        }

        public function searchMovie(string $title, ?int $year = null, bool $tryFallback = true, bool $skipYearExtraction = false): ?array
        {
            return null;
        }
    }
}

namespace Tests {
    require_once __DIR__.'/../Plugin.php';

    use App\Plugins\Support\PluginActionResult;
    use App\Services\TmdbService;
    use AppLocalPlugins\EpgEnricher\Plugin;
    use ReflectionClass;

    function assertCategory(string $expected, array $programme, string $message): void
    {
        if (($programme['category'] ?? null) !== $expected) {
            fwrite(STDERR, $message."\nExpected: {$expected}\nActual: ".var_export($programme['category'] ?? null, true)."\n");
            exit(1);
        }
    }

    $plugin = new Plugin();
    $method = (new ReflectionClass($plugin))->getMethod('enrichProgrammeFromTmdb');
    $method->setAccessible(true);

    $enrich = static function (
        array $programme,
        bool $overwrite = false,
        bool $enrichCategories = true,
        bool $mapGenres = true,
        bool $keywordDetection = true,
        bool $enrichPosters = false,
        bool $enrichDescriptions = false,
        bool $tmdbMatch = true,
    ) use ($plugin, $method): array {
        $cache = [];
        $seasonCache = [];
        $imagesCache = [];
        $args = [
            &$programme,
            new TmdbService($tmdbMatch),
            &$cache,
            $overwrite,
            $enrichCategories,
            $enrichDescriptions,
            $enrichPosters,
            false,
            $mapGenres,
            false,
            $keywordDetection,
            false,
            &$seasonCache,
            &$imagesCache,
            [],
        ];
        $result = $method->invokeArgs($plugin, $args);
        if ($result instanceof PluginActionResult) {
            fwrite(STDERR, "Unexpected action result: {$result->summary}\n");
            exit(1);
        }

        return $programme;
    };

    $keyword = $enrich([
        'title' => 'News Fixture - S01E01',
        'category' => 'Documentary',
        'episode_num' => 'S01E01',
    ], tmdbMatch: false);
    assertCategory('Documentary', $keyword, 'Keyword detection must preserve a non-empty category when overwrite is disabled.');

    $needsFix = $enrich([
        'title' => 'Series Fixture - S01E01',
        'category' => 'Documentary',
        'episode_num' => 'S01E01',
    ], keywordDetection: false, tmdbMatch: false);
    assertCategory('Documentary', $needsFix, 'No-TMDB series fallback must preserve a non-empty category when overwrite is disabled.');

    $tmdbGenre = $enrich([
        'title' => 'Category Fixture - S01E01',
        'category' => 'Documentary',
        'episode_num' => 'S01E01',
    ], keywordDetection: false);
    assertCategory('Documentary', $tmdbGenre, 'TMDB genre mapping must preserve a non-empty category when overwrite is disabled.');

    $existingMapper = $enrich([
        'title' => 'Category Fixture',
        'category' => 'Basketball',
    ], enrichCategories: false, enrichDescriptions: true);
    assertCategory('Basketball', $existingMapper, 'Existing-category mapping must preserve a non-empty category when overwrite is disabled.');

    $missing = $enrich([
        'title' => 'News Fixture - S01E01',
        'episode_num' => 'S01E01',
    ], tmdbMatch: false);
    assertCategory('News', $missing, 'A missing category should still be filled when overwrite is disabled.');

    $explicitOverwrite = $enrich([
        'title' => 'News Fixture - S01E01',
        'category' => 'Documentary',
        'episode_num' => 'S01E01',
    ], overwrite: true, tmdbMatch: false);
    assertCategory('News', $explicitOverwrite, 'Explicit overwrite should still replace an existing category.');

    $explicitExistingMapping = $enrich([
        'title' => 'Category Fixture',
        'category' => 'Basketball',
    ], overwrite: true, enrichCategories: false, enrichDescriptions: true);
    assertCategory('Sports', $explicitExistingMapping, 'Explicit overwrite should still remap an existing category.');

    echo "Category overwrite protection tests passed.\n";
}
