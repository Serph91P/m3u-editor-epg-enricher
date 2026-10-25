<?php

// Standalone offline regression runner: load doubles/fixtures, never the legacy
// suite's assertions. Every case runs even if an earlier contract fails.
// Run: php tests/PosterOverwriteLocaleRegressionTest.php
// Positive controls only: php tests/PosterOverwriteLocaleRegressionTest.php --controls
putenv('TMDB_FIXTURES_ONLY=1');
require_once __DIR__.'/TmdbArtworkRepairTest.php';

use App\Services\CandidateTmdbService;
use App\Settings\GeneralSettings;
use AppLocalPlugins\EpgEnricher\Plugin;
use Illuminate\Support\Facades\FakeHttpResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function posterContractSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected: '.json_encode($expected, JSON_UNESCAPED_SLASHES).' Actual: '.json_encode($actual, JSON_UNESCAPED_SLASHES));
    }
}

function posterContractImages(array $languages = ['en']): array
{
    return [
        'posters' => array_map(static fn ($language): array => [
            'file_path' => '/synthetic-poster-'.($language ?? 'neutral').'.jpg',
            'iso_639_1' => $language, 'width' => 1000, 'height' => 1500,
        ], $languages),
        'backdrops' => [[
            'file_path' => '/synthetic-backdrop.jpg', 'iso_639_1' => null,
            'width' => 1920, 'height' => 1080,
        ]],
        'logos' => [],
    ];
}

function posterContractService(string $media = 'movie', string $locale = 'en', bool $detailsPoster = true): CandidateTmdbService
{
    $poster = $detailsPoster ? 'https://image.tmdb.org/t/p/w500/synthetic-poster-en.jpg' : null;
    $backdrop = 'https://image.tmdb.org/t/p/w1280/synthetic-backdrop.jpg';
    if ($media === 'tv') {
        return new CandidateTmdbService(
            tvCandidates: [['tmdb_id' => 92002, 'name' => 'Synthetic Serial', 'original_name' => 'Synthetic Serial', 'first_air_date' => '2020-01-01', 'overview' => 'Synthetic series overview.']],
            tvDetails: [92002 => Tests\normalizedTvDetailsFixture(92002, 'Synthetic Serial', 'Synthetic series overview.', $poster, $backdrop)],
            seasonDetails: ['92002:1' => ['episodes' => [[
                'episode_number' => 2, 'name' => 'Synthetic Chapter',
                'still_path' => '/synthetic-episode-'.$locale.'.jpg',
            ]]]],
        );
    }
    $details = Tests\normalizedMovieDetailsFixture(92001, 'Synthetic Cinema', 'Synthetic replacement overview.', $poster, $backdrop);
    $details['genres'] = 'Drama';
    return new CandidateTmdbService(
        movieCandidates: [['tmdb_id' => 92001, 'title' => 'Synthetic Cinema', 'original_title' => 'Synthetic Cinema', 'release_date' => '2020-01-01', 'overview' => 'Synthetic replacement overview.']],
        movieDetails: [92001 => $details],
    );
}

function posterContractProgramme(string $media = 'movie'): array
{
    $programme = ['title' => $media === 'tv' ? 'Synthetic Serial' : 'Synthetic Cinema', 'category' => $media === 'tv' ? 'Series' : 'Movie', 'desc' => 'Existing provider text.'];
    if ($media === 'tv') {
        $programme['episode_num'] = '0.1.';
        $programme['episode_nums'] = [['system' => 'xmltv_ns', 'value' => '0.1.']];
    }
    return $programme;
}

function posterContractEnrich(array $before, array $images, bool $overwriteArtwork = true, bool $backdrops = true, string $locale = 'de-DE', ?CandidateTmdbService $service = null, ?array &$seasonCache = null, ?array &$imagesCache = null, ?array $fallbackImages = null): array
{
    $GLOBALS['tmdbTestSettings'] = new GeneralSettings();
    $GLOBALS['tmdbTestSettings']->tmdb_api_key = 'synthetic-offline';
    // Deliberately differs from the plugin locale in locale regression cases.
    $GLOBALS['tmdbTestSettings']->tmdb_language = 'de-DE';
    Http::$calls = [];
    Http::$responses = [new FakeHttpResponse(true, $images)];
    if ($fallbackImages !== null) {
        Http::$responses[] = new FakeHttpResponse(true, $fallbackImages);
    }
    $plugin = new Plugin();
    $programme = $before;
    $cache = [];
    $seasonCache ??= [];
    $imagesCache ??= [];
    $result = Tests\enrich($plugin, new ReflectionMethod($plugin, 'enrichProgrammeFromTmdb'), $programme, $service ?? posterContractService(), $cache,
        lookupContext: ['tmdb_language' => $locale], seasonCache: $seasonCache, imagesCache: $imagesCache,
        enrichEpisodeDetails: isset($before['episode_num']), overwriteArtwork: $overwriteArtwork, enrichBackdrops: $backdrops);
    // The optional third parameter is the explicit artwork replacement policy.
    // On the baseline PHP ignores the surplus argument, exposing suppression as
    // an assertion failure rather than failing on a missing helper/signature.
    $patch = (new ReflectionMethod($plugin, 'canonicalHostChanges'))->invoke($plugin, $before, $programme, $overwriteArtwork);
    $published = array_replace($before, $patch); // real host's field replacement semantics
    return compact('programme', 'patch', 'published', 'result');
}

function posterContractRole(array $published, string $role): array
{
    return array_values(array_filter($published['images'] ?? [], static fn (array $image): bool => ($image['type'] ?? '') === $role));
}

$poster = 'https://image.tmdb.org/t/p/w500/synthetic-poster-en.jpg';
$backdrop = 'https://image.tmdb.org/t/p/w1280/synthetic-backdrop.jpg';
$source = 'https://provider.invalid/synthetic-scene.jpg';
$cases = [];
foreach (['movie', 'tv'] as $media) {
    $cases[$media.':qualified-poster-primary'] = static function () use ($media, $poster): void {
        $out = posterContractEnrich(posterContractProgramme($media), posterContractImages(), service: posterContractService($media));
        posterContractSame($poster, $out['published']['icon'] ?? null, 'Qualified poster must be Primary through enrichment and host patch.');
        posterContractSame(1, count(posterContractRole($out['published'], 'poster')), 'The canonical host patch must contain exactly one typed poster, not a duplicate legacy terminal Primary.');
    };
}
$cases['poster-only:primary-with-backdrops-disabled'] = static function () use ($poster, $source): void {
    $before = posterContractProgramme() + ['icon' => $source];
    $out = posterContractEnrich($before, posterContractImages(), backdrops: false);
    posterContractSame($poster, $out['published']['icon'] ?? null, 'enrich_posters alone must replace Primary.');
    posterContractSame([], posterContractRole($out['published'], 'backdrop'), 'Disabled backdrop enrichment must not add backdrops.');
};
$cases['no-overwrite:existing-scene-primary'] = static function () use ($source): void {
    $before = posterContractProgramme() + ['icon' => $source, 'images' => [['url' => $source, 'type' => 'still', 'orient' => 'L', 'width' => 1920, 'height' => 1080, 'size' => 3]]];
    $out = posterContractEnrich($before, posterContractImages(), overwriteArtwork: false);
    posterContractSame($source, $out['published']['icon'] ?? null, 'Without overwrite even an existing scene Primary must survive.');
    posterContractSame([], posterContractRole($out['published'], 'poster'), 'Preserve must not introduce a competing poster that consumers select ahead of the protected scene.');
    posterContractSame([], posterContractRole($out['published'], 'backdrop'), 'Preserve must not introduce a backdrop that consumers select ahead of the protected scene fallback.');
    $still = posterContractRole($out['published'], 'still')[0];
    foreach ($before['images'][0] as $field => $value) {
        posterContractSame($value, $still[$field] ?? null, 'The existing scene must retain its real role and geometry: '.$field);
    }
};
$cases['artwork-only:preserves-description-and-categories'] = static function () use ($poster, $source): void {
    $before = posterContractProgramme() + ['icon' => $source];
    $out = posterContractEnrich($before, posterContractImages());
    posterContractSame($before['desc'], $out['published']['desc'], 'Artwork-only must not replace description.');
    posterContractSame($before['category'], $out['published']['category'], 'Artwork-only must not replace category.');
    posterContractSame(false, $out['result']['description'], 'Description update must remain false.');
    posterContractSame(false, $out['result']['category'], 'Category update must remain false.');
    posterContractSame($poster, $out['published']['icon'] ?? null, 'Artwork-only must actually replace the picture.');
};
$cases['control:separate-backdrop-and-verified-episode-thumb'] = static function () use ($backdrop): void {
    $out = posterContractEnrich(posterContractProgramme('tv'), posterContractImages(), service: posterContractService('tv'));
    posterContractSame($backdrop, posterContractRole($out['published'], 'backdrop')[0]['url'] ?? null, 'Backdrop must retain its own role.');
    posterContractSame('https://image.tmdb.org/t/p/original/synthetic-episode-en.jpg', posterContractRole($out['published'], 'still')[0]['url'] ?? null, 'Verified episode image must reach canonical still/Thumb, not be relabelled backdrop.');
};
foreach ([
    'untyped' => ['url' => 'https://provider.invalid/legacy-untyped.jpg'],
    'dimensionless-poster' => ['url' => 'https://provider.invalid/legacy-poster.jpg', 'type' => 'poster', 'orient' => 'P', 'width' => 0, 'height' => 0, 'size' => 3],
    'dimensionless-still' => ['url' => 'https://provider.invalid/legacy-still.jpg', 'type' => 'still', 'orient' => 'L', 'width' => 0, 'height' => 0, 'size' => 3],
] as $shape => $legacy) {
    $cases['legacy-overwrite:'.$shape] = static function () use ($legacy, $poster): void {
        $before = posterContractProgramme() + ['icon' => $legacy['url'], 'images' => [$legacy]];
        $out = posterContractEnrich($before, posterContractImages());
        posterContractSame(true, isset($out['patch']['images']), 'Explicit artwork overwrite must not suppress the entire canonical images patch.');
        posterContractSame($poster, posterContractRole($out['published'], 'poster')[0]['url'] ?? null, 'Validated replacement poster must reach host.');
    };
    $cases['control:lossless-no-overwrite-'.$shape] = static function () use ($legacy): void {
        $before = ['images' => [$legacy]];
        $after = ['images' => [$legacy, ['url' => 'https://image.tmdb.org/t/p/w500/new.jpg', 'type' => 'poster', 'orient' => 'P', 'width' => 500, 'height' => 750, 'size' => 2]]];
        $patch = (new ReflectionMethod(Plugin::class, 'canonicalHostChanges'))->invoke(new Plugin(), $before, $after);
        posterContractSame([], $patch, 'Default no-overwrite must abstain rather than lose legacy image data.');
        posterContractSame($before, array_replace($before, $patch), 'Source must survive host application unchanged.');
    };
}
$cases['locale:effective-request-not-global'] = static function (): void {
    posterContractEnrich(posterContractProgramme(), posterContractImages(['ja', 'de']), locale: 'ja-JP');
    posterContractSame(1, count(Http::$calls), 'A qualified preferred poster must use exactly one images request.');
    // An unfiltered request is also valid: it enables universal poster fallback.
    $filter = Http::$calls[0]['query']['include_image_language'] ?? null;
    posterContractSame(true, $filter === null || (str_contains($filter, 'ja') && ! str_contains($filter, 'de')), 'Images request must honor effective ja-JP, not global de-DE.');
};
$cases['locale:images-cache-isolated-and-reused'] = static function (): void {
    $imagesCache = [];
    $seasons = [];
    foreach (['ja-JP', 'fr-FR'] as $locale) {
        posterContractEnrich(posterContractProgramme(), posterContractImages(['ja', 'fr']), locale: $locale, seasonCache: $seasons, imagesCache: $imagesCache);
    }
    $logic = (new ReflectionClass(Plugin::class))->getConstant('ENRICHMENT_LOGIC_VERSION');
    posterContractSame(['movie:92001:ja-jp:'.$logic, 'movie:92001:fr-fr:'.$logic], array_keys($imagesCache), 'Images cache must separate effective locales and logic versions.');
    posterContractEnrich(posterContractProgramme(), posterContractImages(['ja', 'fr']), locale: 'fr-FR', seasonCache: $seasons, imagesCache: $imagesCache);
    posterContractSame([], Http::$calls, 'Same locale must reuse cached images.');
};
foreach ([
    'preferred' => [['de', 'ja', null, 'en'], 'ja'],
    'neutral-before-English' => [['de', 'en', null], 'neutral'],
    'English-before-other' => [['de', 'en'], 'en'],
    'universal-other-language' => [['fr'], 'fr'],
] as $name => [$languages, $selected]) {
    $cases['locale:ranking-'.$name] = static function () use ($languages, $selected): void {
        $out = posterContractEnrich(posterContractProgramme(), posterContractImages($languages), locale: 'ja-JP');
        posterContractSame('https://image.tmdb.org/t/p/w500/synthetic-poster-'.$selected.'.jpg', posterContractRole($out['published'], 'poster')[0]['url'] ?? null, 'Poster ranking must be preferred, neutral, English, then another valid language.');
    };
}
$cases['locale:season-cache-isolated-and-reused'] = static function (): void {
    $seasons = [];
    $imagesCache = [];
    $services = [];
    foreach (['ja-JP', 'fr-FR', 'fr-FR'] as $locale) {
        $service = posterContractService('tv', $locale);
        $services[] = $service;
        $out = posterContractEnrich(posterContractProgramme('tv'), posterContractImages(), locale: $locale, service: $service, seasonCache: $seasons, imagesCache: $imagesCache);
        posterContractSame('https://image.tmdb.org/t/p/original/synthetic-episode-'.$locale.'.jpg', posterContractRole($out['published'], 'still')[0]['url'] ?? null, 'Season cache must not reuse an episode still from another effective locale.');
    }
    posterContractSame([1, 1, 0], array_map(static fn ($service): int => $service->seasonRequests, $services), 'Fetch each locale once, then reuse its season cache.');
};
$cases['control:reject-nonportrait-and-unknown-poster-geometry'] = static function (): void {
    foreach ([[1920, 1080], [1000, 1000], [0, 0]] as [$width, $height]) {
        $images = posterContractImages();
        $images['posters'][0]['width'] = $width;
        $images['posters'][0]['height'] = $height;
        $images['posters'][0]['aspect_ratio'] = $height > 0 ? $width / $height : null;
        $out = posterContractEnrich(posterContractProgramme(), $images, service: posterContractService(detailsPoster: false), fallbackImages: $images);
        posterContractSame([], posterContractRole($out['published'], 'poster'), 'Role name alone must never qualify invalid/unknown portrait geometry.');
    }
};
$cases['locale:unfiltered-universal-poster-fallback'] = static function (): void {
    $filtered = posterContractImages();
    $filtered['posters'] = [];
    $out = posterContractEnrich(posterContractProgramme(), $filtered, locale: 'ja-JP', service: posterContractService(detailsPoster: false), fallbackImages: posterContractImages(['fr']));
    posterContractSame('https://image.tmdb.org/t/p/w500/synthetic-poster-fr.jpg', posterContractRole($out['published'], 'poster')[0]['url'] ?? null, 'When preferred/neutral/en have no valid poster, fetch other available languages.');
    posterContractSame(2, count(Http::$calls), 'Universal fallback must be bounded to one additional request.');
    posterContractSame(false, isset(Http::$calls[1]['query']['include_image_language']), 'Fallback request must not retain the language filter.');
};
$cases['control:unmatched-identity-cannot-publish-artwork'] = static function (): void {
    $before = posterContractProgramme();
    $out = posterContractEnrich($before, posterContractImages(), service: new CandidateTmdbService());
    posterContractSame([], $out['patch'], 'Unresolved identity must not publish even geometrically valid images.');
    posterContractSame([], Http::$calls, 'Unresolved identity must not fetch artwork.');
};
$cases['control:artwork-only-keeps-text-and-category'] = static function (): void {
    $before = posterContractProgramme();
    $out = posterContractEnrich($before, posterContractImages());
    posterContractSame([$before['desc'], $before['category']], [$out['published']['desc'], $out['published']['category']], 'Replacing artwork must leave provider metadata intact.');
    posterContractSame([false, false], [$out['result']['description'], $out['result']['category']], 'Artwork-only must not report metadata updates.');
};
$cases['control:no-overwrite-trusted-poster-primary'] = static function (): void {
    $source = 'https://provider.invalid/trusted-poster.jpg';
    $before = posterContractProgramme() + ['icon' => $source, 'images' => [['url' => $source, 'type' => 'poster', 'orient' => 'P', 'width' => 500, 'height' => 750, 'size' => 2]]];
    $out = posterContractEnrich($before, posterContractImages(), overwriteArtwork: false, backdrops: false);
    posterContractSame($before, $out['published'], 'A complete trusted poster must survive without overwrite.');
};

$failures = 0;
$cases['cache:negative-legacy-entry-cannot-suppress-fresh-poster'] = static function (): void {
    $empty = ['posters' => [], 'backdrops' => [], 'logos' => []];
    $cache = ['movie:92001:de-de' => $empty];
    $seasons = [];
    $out = posterContractEnrich(posterContractProgramme(), posterContractImages(['de']), service: posterContractService(detailsPoster: false), seasonCache: $seasons, imagesCache: $cache);
    posterContractSame('https://image.tmdb.org/t/p/w500/synthetic-poster-de.jpg', posterContractRole($out['published'], 'poster')[0]['url'] ?? null, 'An unversioned negative legacy cache must not hide a newly available poster.');
    posterContractSame(1, count(Http::$calls), 'Ignoring stale negative cache must perform one fresh preferred request.');
};
$cases['control:images-request-reuse'] = static function (): void {
    $cache = [];
    $seasons = [];
    posterContractEnrich(posterContractProgramme(), posterContractImages(['de']), seasonCache: $seasons, imagesCache: $cache);
    posterContractSame(1, count(Http::$calls), 'First use must fetch the qualified preferred poster once.');
    $out = posterContractEnrich(posterContractProgramme(), posterContractImages(['de']), seasonCache: $seasons, imagesCache: $cache);
    posterContractSame([], Http::$calls, 'Repeated media/locale must reuse images rather than fetch again.');
    posterContractSame('https://image.tmdb.org/t/p/w500/synthetic-poster-de.jpg', posterContractRole($out['published'], 'poster')[0]['url'] ?? null, 'Reused images must still publish the selected poster role.');
};
$cases['cache:missing-poster-reused-only-within-invocation'] = static function (): void {
    $empty = ['posters' => [], 'backdrops' => [], 'logos' => []];
    $cache = [];
    $seasons = [];
    posterContractEnrich(posterContractProgramme(), $empty, service: posterContractService(detailsPoster: false), seasonCache: $seasons, imagesCache: $cache, fallbackImages: $empty);
    posterContractSame(2, count(Http::$calls), 'Missing preferred and fallback posters must use exactly two requests.');
    posterContractSame(true, array_values($cache)[0]['_retry_images'] ?? false, 'A missing poster result must remain retryable on a later invocation.');
    posterContractEnrich(posterContractProgramme(), $empty, service: posterContractService(detailsPoster: false), seasonCache: $seasons, imagesCache: $cache, fallbackImages: $empty);
    posterContractSame([], Http::$calls, 'Missing poster response must still deduplicate requests within the current invocation.');
};
$cases['cache:retryable-miss-not-persisted'] = static function (): void {
    $logic = (new ReflectionClass(Plugin::class))->getConstant('ENRICHMENT_LOGIC_VERSION');
    $goodKey = 'movie:92001:de-de:'.$logic;
    $missKey = 'movie:92003:de-de:'.$logic;
    $good = posterContractImages(['de']);
    $miss = ['posters' => [], 'backdrops' => [], 'logos' => [], '_retry_images' => true];
    Storage::$writes = [];
    (new ReflectionMethod(Plugin::class, 'saveTmdbImagesCache'))->invoke(new Plugin(), [$goodKey => $good, $missKey => $miss]);
    $stored = json_decode(Storage::$writes['plugin-data/epg-enricher/tmdb-images-cache.json'] ?? 'null', true, flags: JSON_THROW_ON_ERROR);
    posterContractSame([$goodKey => $good], $stored, 'Persist successful image responses, but never make an invocation-local poster miss durable.');
};

foreach ([false, true] as $metadataOverwrite) {
    foreach ([false, true] as $artworkOverwrite) {
        $cases['preserve:settings-and-cache-'.(int) $metadataOverwrite.'-'.(int) $artworkOverwrite] = static function () use ($metadataOverwrite, $artworkOverwrite, $source, $poster): void {
            $GLOBALS['tmdbTestSettings'] = new GeneralSettings();
            $GLOBALS['tmdbTestSettings']->tmdb_api_key = 'synthetic-offline';
            $before = posterContractProgramme() + ['icon' => $source, 'images' => [['url' => $source, 'type' => 'still', 'width' => 1920, 'height' => 1080, 'orient' => 'L', 'size' => 3, 'provider_extension' => ['keep' => true]]]];
            $settings = ['overwrite_existing' => $metadataOverwrite, 'replace_existing_artwork' => $artworkOverwrite, 'enrich_posters' => true, 'enrich_backdrops' => true, 'enrich_categories' => true, 'enrich_descriptions' => true, 'enrich_episode_details' => false, 'keyword_category_detection' => false];
            $plugin = new Plugin();
            $cache = $seasons = $images = [];
            $service = posterContractService();
            $method = new ReflectionMethod($plugin, 'enrichProgrammeWithSettings');
            Http::$responses = [new FakeHttpResponse(true, posterContractImages())];
            $first = null;
            foreach ([false, true] as $replay) {
                $programme = $before;
                $arguments = [&$programme, $service, &$cache, &$seasons, &$images, $settings, 1, 'en-US'];
                $result = $method->invokeArgs($plugin, $arguments);
                $replace = $metadataOverwrite || $artworkOverwrite;
                posterContractSame($replace ? $poster : $source, $programme['icon'], 'Effective overwrite must control Primary on fresh and cached enrichment.');
                posterContractSame($metadataOverwrite ? 'Synthetic replacement overview.' : $before['desc'], $programme['desc'], 'Artwork policy must not change metadata overwrite.');
                posterContractSame($metadataOverwrite ? 'Drama' : $before['category'], $programme['category'], 'Category overwrite must remain independent.');
                posterContractSame(true, in_array($before['images'][0], $programme['images'], true), 'Source image including unknown fields must survive.');
                if (! $replace) {
                    posterContractSame([], posterContractRole($programme, 'poster'), 'Preserve must not append a competing poster on cache replay.');
                    posterContractSame([], posterContractRole($programme, 'backdrop'), 'Preserve must not append a competing backdrop on cache replay.');
                }
                if ($replay) {
                    posterContractSame(true, $result['cache_hit'], 'Replay must actually exercise the identity cache.');
                    posterContractSame($first, $programme, 'Fresh and cached artwork must be deterministic.');
                }
                $first = $programme;
            }
        };
    }
}
$cases['preserve:empty-primary-can-fill'] = static function () use ($poster): void {
    $out = posterContractEnrich(posterContractProgramme(), posterContractImages(), overwriteArtwork: false);
    posterContractSame($poster, $out['published']['icon'] ?? null, 'An empty Primary must still receive a qualified poster without overwrite.');
};
$cases['preserve:poster-can-gain-secondary-backdrop'] = static function () use ($backdrop): void {
    $url = 'https://provider.invalid/keep-poster.jpg';
    $before = posterContractProgramme() + ['icon' => $url, 'images' => [['url' => $url, 'type' => 'poster', 'width' => 500, 'height' => 750, 'orient' => 'P', 'size' => 2]]];
    $out = posterContractEnrich($before, posterContractImages(), overwriteArtwork: false);
    posterContractSame($url, $out['published']['icon'], 'Adding a safe secondary backdrop must preserve poster Primary.');
    posterContractSame($backdrop, posterContractRole($out['published'], 'backdrop')[0]['url'] ?? null, 'A noncompeting missing backdrop may still be added.');
};

$executed = 0;
foreach ($cases as $name => $case) {
    if (in_array('--controls', $argv, true) && ! str_starts_with($name, 'control:')) {
        continue;
    }
    $executed++;
    try {
        $case();
        echo "PASS {$name}\n";
    } catch (Throwable $error) {
        $failures++;
        fwrite(STDERR, "FAIL {$name}: {$error->getMessage()}\n");
    }
}
echo json_encode(['cases' => $executed, 'passed' => $executed - $failures, 'failed' => $failures], JSON_THROW_ON_ERROR)."\n";
exit($failures === 0 ? 0 : 1);
