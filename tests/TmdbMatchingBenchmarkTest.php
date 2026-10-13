<?php

namespace Tests;

putenv('TMDB_FIXTURES_ONLY=1');
require_once __DIR__.'/TmdbArtworkRepairTest.php';

use App\Services\CandidateTmdbService;
use App\Settings\GeneralSettings;
use AppLocalPlugins\EpgEnricher\Plugin;
use ReflectionClass;

$GLOBALS['tmdbTestSettings'] = new GeneralSettings();
$plugin = new Plugin();
$enrich = (new ReflectionClass($plugin))->getMethod('enrichProgrammeFromTmdb');
$enrich->setAccessible(true);
$search = (new ReflectionClass($plugin))->getMethod('searchTmdbWithValidation');
$search->setAccessible(true);
$started = hrtime(true);
$metrics = [
    'sample_size' => 11,
    'positive_denominator' => 5,
    'positive_hits' => 0,
    'negative_denominator' => 6,
    'correct_abstentions' => 0,
    'false_positives' => 0,
    'candidate_requests' => 0,
    'alternative_title_requests' => 0,
    'detail_requests' => 0,
    'season_requests' => 0,
    'cache_first_candidate_requests' => 0,
    'cache_repeat_candidate_requests' => 0,
    'cache_repeat_hit' => false,
];

$run = static function (
    CandidateTmdbService $tmdb,
    string $title,
    ?string $mediaType = null,
    ?int $year = null,
    string $description = '',
    ?array $episodeIdentity = null,
    string $locale = 'de-DE',
) use ($plugin, $search, &$metrics): ?array {
    $compound = null;
    $episodePath = null;
    $result = $search->invokeArgs($plugin, [
        $tmdb,
        $title,
        $mediaType,
        $year,
        $description,
        &$compound,
        null,
        $episodeIdentity,
        &$episodePath,
        $locale,
    ]);
    $metrics['candidate_requests'] += $tmdb->tvCandidateSearches + $tmdb->movieCandidateSearches;
    $metrics['alternative_title_requests'] += $tmdb->tvAlternativeRequests + $tmdb->movieAlternativeRequests;
    $metrics['detail_requests'] += $tmdb->tvDetailsRequests + $tmdb->movieDetailsRequests;
    $metrics['season_requests'] += $tmdb->seasonRequests;

    return $result;
};

$deepCandidates = [];
foreach (range(1, 5) as $index) {
    $deepCandidates[] = [
        'tmdb_id' => 900 + $index,
        'name' => 'Unrelated Archive '.$index,
        'original_name' => 'Unrelated Archive '.$index,
        'first_air_date' => '2024-01-01',
        'overview' => 'No shared identity.',
    ];
}
$deepCandidates[] = [
    'tmdb_id' => 910,
    'name' => 'Deep Archive',
    'original_name' => 'Deep Archive',
    'first_air_date' => '2024-01-01',
    'overview' => 'The bounded rank-six identity.',
];
$deep = new CandidateTmdbService(
    tvCandidates: $deepCandidates,
    tvDetails: [910 => normalizedTvDetailsFixture(910, 'Deep Archive')],
);
$metrics['positive_hits'] += (($run($deep, 'Deep Archive', 'tv', 2024)['tmdb_id'] ?? null) === 910) ? 1 : 0;

$yearTitle = 'Harbor Feature';
$correctMovie = [
    'tmdb_id' => 920,
    'title' => $yearTitle,
    'original_title' => $yearTitle,
    'release_date' => '2021-01-01',
    'overview' => 'A bounded year recovery fixture.',
];
$yearRecovery = new CandidateTmdbService(
    movieDetails: [920 => normalizedMovieDetailsFixture(920, $yearTitle)],
    movieCandidatesByYear: [
        $yearTitle.'|2020' => [[
            ...$correctMovie,
            'tmdb_id' => 921,
            'title' => 'Harbor Patrol Feature',
            'original_title' => 'Harbor Patrol Feature',
            'release_date' => '2020-01-01',
        ]],
        $yearTitle.'|none' => [$correctMovie],
    ],
);
$metrics['positive_hits'] += (($run($yearRecovery, $yearTitle, 'movie', 2020)['tmdb_id'] ?? null) === 920) ? 1 : 0;

$localizedCandidate = [
    'tmdb_id' => 930,
    'name' => 'Coastal Residence',
    'original_name' => 'Coastal Residence',
    'first_air_date' => '2020-01-01',
    'overview' => 'A bounded international alias fixture.',
];
$foreignCorroborated = new CandidateTmdbService(
    tvCandidates: [$localizedCandidate],
    tvDetails: [930 => normalizedTvDetailsFixture(930, 'Coastal Residence')],
    tvAlternativeTitles: [930 => [['title' => 'Haus am Meer', 'iso_3166_1' => 'US']]],
);
$metrics['positive_hits'] += (($run($foreignCorroborated, 'Haus am Meer', 'tv', 2020)['tmdb_id'] ?? null) === 930) ? 1 : 0;

$foreignUncorroborated = new CandidateTmdbService(
    tvCandidates: [$localizedCandidate],
    tvAlternativeTitles: [930 => [['title' => 'Haus am Meer', 'iso_3166_1' => 'US']]],
);
$negative = $run($foreignUncorroborated, 'Haus am Meer', 'tv');
$metrics['correct_abstentions'] += $negative === null ? 1 : 0;
$metrics['false_positives'] += $negative === null ? 0 : 1;

$aliasTie = new CandidateTmdbService(
    tvCandidates: [$localizedCandidate, [...$localizedCandidate, 'tmdb_id' => 931]],
    tvAlternativeTitles: [
        930 => [['title' => 'Haus am Meer', 'iso_3166_1' => 'DE']],
        931 => [['title' => 'Haus am Meer', 'iso_3166_1' => 'DE']],
    ],
);
$negative = $run($aliasTie, 'Haus am Meer', 'tv', 2020);
$metrics['correct_abstentions'] += $negative === null ? 1 : 0;
$metrics['false_positives'] += $negative === null ? 0 : 1;

$wrongYear = new CandidateTmdbService(
    movieCandidatesByYear: [
        $yearTitle.'|2020' => [],
        $yearTitle.'|none' => [[...$correctMovie, 'release_date' => '2023-01-01']],
    ],
);
$negative = $run($wrongYear, $yearTitle, 'movie', 2020);
$metrics['correct_abstentions'] += $negative === null ? 1 : 0;
$metrics['false_positives'] += $negative === null ? 0 : 1;

// Matrix-derived controls: exact year disambiguates remakes; an exact unique
// movie remains stable; absent candidates and unresolved same-name movie/TV or
// remake competitors must abstain. Titles and IDs are intentionally synthetic.
$remakeTitle = 'Northern Path';
$remake = new CandidateTmdbService(
    tvCandidates: [[
        'tmdb_id' => 950, 'name' => $remakeTitle, 'original_name' => $remakeTitle,
        'first_air_date' => '1972-01-01', 'overview' => 'A television namesake.',
    ]],
    movieCandidates: [
        ['tmdb_id' => 951, 'title' => $remakeTitle, 'original_title' => $remakeTitle, 'release_date' => '1984-01-01', 'overview' => 'The original feature.'],
        ['tmdb_id' => 952, 'title' => $remakeTitle, 'original_title' => $remakeTitle, 'release_date' => '2010-01-01', 'overview' => 'The later feature.'],
    ],
    movieDetails: [952 => normalizedMovieDetailsFixture(952, $remakeTitle)],
);
$metrics['positive_hits'] += (($run($remake, $remakeTitle, null, 2010)['tmdb_id'] ?? null) === 952) ? 1 : 0;

$uniqueTitle = 'Unbroken Circle';
$unique = new CandidateTmdbService(
    movieCandidates: [[
        'tmdb_id' => 953, 'title' => $uniqueTitle, 'original_title' => $uniqueTitle,
        'release_date' => '1987-01-01', 'overview' => 'A unique feature identity.',
    ]],
    movieDetails: [953 => normalizedMovieDetailsFixture(953, $uniqueTitle)],
);
$metrics['positive_hits'] += (($run($unique, $uniqueTitle, null, 1987)['tmdb_id'] ?? null) === 953) ? 1 : 0;

$sameNameCrossMedia = new CandidateTmdbService(
    tvCandidates: [[
        'tmdb_id' => 954, 'name' => 'Silent Signal', 'original_name' => 'Silent Signal',
        'first_air_date' => '1972-01-01', 'overview' => 'A television identity.',
    ]],
    movieCandidates: [[
        'tmdb_id' => 955, 'title' => 'Silent Signal', 'original_title' => 'Silent Signal',
        'release_date' => '1999-01-01', 'overview' => 'A movie identity.',
    ]],
);
$negative = $run($sameNameCrossMedia, 'Silent Signal');
$metrics['correct_abstentions'] += $negative === null ? 1 : 0;
$metrics['false_positives'] += $negative === null ? 0 : 1;

$sameNameRemakes = new CandidateTmdbService(movieCandidates: [
    ['tmdb_id' => 956, 'title' => 'Forest Archer', 'original_title' => 'Forest Archer', 'release_date' => '1991-01-01', 'overview' => 'An older adaptation.'],
    ['tmdb_id' => 957, 'title' => 'Forest Archer', 'original_title' => 'Forest Archer', 'release_date' => '2018-01-01', 'overview' => 'A newer adaptation.'],
]);
$negative = $run($sameNameRemakes, 'Forest Archer', 'movie');
$metrics['correct_abstentions'] += $negative === null ? 1 : 0;
$metrics['false_positives'] += $negative === null ? 0 : 1;

$negative = $run(new CandidateTmdbService(), 'Unlisted Compound Programme');
$metrics['correct_abstentions'] += $negative === null ? 1 : 0;
$metrics['false_positives'] += $negative === null ? 0 : 1;

$cacheTitle = 'Reusable Feature';
$cacheTmdb = new CandidateTmdbService(
    movieCandidates: [[
        'tmdb_id' => 940,
        'title' => $cacheTitle,
        'original_title' => $cacheTitle,
        'release_date' => '2024-01-01',
        'overview' => 'A cache behavior fixture.',
    ]],
    movieDetails: [940 => normalizedMovieDetailsFixture(
        940,
        $cacheTitle,
        posterUrl: 'https://image.tmdb.org/t/p/w500/reusable-feature.jpg',
    )],
);
$cache = [];
$firstProgramme = ['title' => $cacheTitle];
$firstCacheResult = enrich($plugin, $enrich, $firstProgramme, $cacheTmdb, $cache, enrichBackdrops: false);
$firstRequests = $cacheTmdb->tvCandidateSearches + $cacheTmdb->movieCandidateSearches;
$secondProgramme = ['title' => $cacheTitle];
$secondCacheResult = enrich($plugin, $enrich, $secondProgramme, $cacheTmdb, $cache, enrichBackdrops: false);
$secondRequests = $cacheTmdb->tvCandidateSearches + $cacheTmdb->movieCandidateSearches;
$metrics['cache_first_candidate_requests'] = $firstRequests;
$metrics['cache_repeat_candidate_requests'] = $secondRequests - $firstRequests;
$metrics['cache_repeat_hit'] = ($firstCacheResult['cache_hit'] ?? null) === false
    && ($secondCacheResult['cache_hit'] ?? null) === true;

$metrics['elapsed_ms'] = round((hrtime(true) - $started) / 1_000_000, 3);
$metrics['peak_memory_bytes'] = memory_get_peak_usage(true);

echo json_encode($metrics, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

if ($metrics['positive_hits'] !== $metrics['positive_denominator']
    || $metrics['correct_abstentions'] !== $metrics['negative_denominator']
    || $metrics['false_positives'] !== 0
    || $metrics['candidate_requests'] > 20
    || $metrics['cache_repeat_candidate_requests'] !== 0
    || ! $metrics['cache_repeat_hit']) {
    exit(1);
}
