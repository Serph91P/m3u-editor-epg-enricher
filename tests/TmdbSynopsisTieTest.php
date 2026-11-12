<?php

namespace Tests;

putenv('TMDB_FIXTURES_ONLY=1');
require_once __DIR__.'/TmdbArtworkRepairTest.php';

use App\Services\CandidateTmdbService;
use AppLocalPlugins\EpgEnricher\Plugin;
use ReflectionMethod;

$plugin = new Plugin();
$score = new ReflectionMethod($plugin, 'scoreTmdbCandidate');
$select = new ReflectionMethod($plugin, 'selectTmdbIdentityWinner');
$search = new ReflectionMethod($plugin, 'searchTmdbWithValidation');
$title = '47 Harbor Road';
$description = 'Detectives uncover hidden letters beneath ancient bridges during winter storms.';
$tv = ['tmdb_id' => 93001, 'name' => $title, 'original_name' => $title, 'first_air_date' => '2020-01-01', 'overview' => 'Detectives uncover hidden letters.'];
$movie = ['tmdb_id' => 93002, 'title' => $title, 'original_title' => $title, 'release_date' => '2024-01-01', 'overview' => 'Detectives uncover hidden letters beneath ancient bridges during.'];
$scoredTv = $score->invoke($plugin, $tv, 'tv', strtolower($title), null, $description, []);
$scoredMovie = $score->invoke($plugin, $movie, 'movie', strtolower($title), null, $description, []);
assertSameValue([86.0, 86.0, 4, 8], [$scoredTv['_identity_score'], $scoredMovie['_identity_score'], $scoredTv['_identity_overview_overlap'], $scoredMovie['_identity_overview_overlap']], 'The synthetic fixture must reproduce capped equal scores with unequal raw synopsis evidence.');
assertSameValue(93002, $select->invoke($plugin, [$scoredTv, $scoredMovie])['tmdb_id'] ?? null, 'An exact primary title with uniquely stronger synopsis must win a capped score tie.');

// A weaker runner-up must not hide a third rival with near-equal synopsis evidence.
$bestWithCredits = $score->invoke($plugin, $movie + ['cast' => ['Detectives Uncover']], 'movie', strtolower($title), null, $description, []);
$runnerWithCredits = $score->invoke($plugin, [...$tv, 'overview' => 'Detectives.', 'cast' => ['Detectives Uncover', 'Hidden Letters']], 'tv', strtolower($title), null, $description, []);
$closeSynopsisRival = $score->invoke($plugin, [...$movie, 'tmdb_id' => 93003, 'overview' => 'Detectives uncover hidden letters beneath ancient bridges.'], 'movie', strtolower($title), null, $description, []);
assertSameValue([90.0, 88.0, 86.0], [$bestWithCredits['_identity_score'], $runnerWithCredits['_identity_score'], $closeSynopsisRival['_identity_score']], 'Three-way guard fixture must keep every rival within the ordinary eight-point margin.');
assertSameValue(null, $select->invoke($plugin, [$bestWithCredits, $runnerWithCredits, $closeSynopsisRival]), 'A strong synopsis exception must beat every close rival, not just the score runner-up.');
foreach ([
    [$bestWithCredits, $closeSynopsisRival, $runnerWithCredits],
    [$runnerWithCredits, $bestWithCredits, $closeSynopsisRival],
    [$runnerWithCredits, $closeSynopsisRival, $bestWithCredits],
    [$closeSynopsisRival, $bestWithCredits, $runnerWithCredits],
    [$closeSynopsisRival, $runnerWithCredits, $bestWithCredits],
] as $order) {
    assertSameValue(null, $select->invoke($plugin, $order), 'Three-way abstention must hold for every input permutation.');
}
$strongTv = [...$scoredMovie, 'tmdb_id' => 93005, '_media_type' => 'tv'];
$weakMovie = [...$scoredTv, '_media_type' => 'movie'];
assertSameValue(93005, $select->invoke($plugin, [$weakMovie, $strongTv])['tmdb_id'] ?? null, 'Synopsis evidence must favor TV just as it favors movies; media type is not a tie-break.');

foreach ([[$scoredTv, $scoredMovie], [$scoredMovie, $scoredTv]] as $order) {
    assertSameValue(93002, $select->invoke($plugin, $order)['tmdb_id'] ?? null, 'Input ordering must not choose the media type.');
}
$equalRival = [...$scoredMovie, 'tmdb_id' => 93004];
assertSameValue(null, $select->invoke($plugin, [$scoredMovie, $equalRival]), 'Equal synopsis evidence must abstain.');
assertSameValue(null, $select->invoke($plugin, [$scoredTv, $scoredMovie, $equalRival]), 'Two equally strong rivals among three candidates must abstain.');
$insufficient = $score->invoke($plugin, [...$movie, 'overview' => 'Detectives uncover hidden letters beneath ancient.'], 'movie', strtolower($title), null, $description, []);
assertSameValue(null, $select->invoke($plugin, [$scoredMovie, $insufficient]), 'Fewer than three extra overlap tokens must abstain.');
$creditsTie = new ReflectionMethod($plugin, 'hasStrongDescriptionIdentityLead');
$twoPeople = [...$bestWithCredits, '_identity_person_score' => 8, '_identity_overview_overlap' => 1];
assertSameValue(false, $creditsTie->invoke($plugin, $twoPeople, [...$runnerWithCredits, '_identity_description_score' => 0], 0.0), 'Even two people and a description lead cannot release a credits-only score tie.');
assertSameValue(null, $select->invoke($plugin, [[...$scoredMovie, '_identity_primary_title_score' => 0.9], $scoredTv]), 'An alias or non-exact primary title must not use the synopsis exception.');
assertSameValue(null, $select->invoke($plugin, [[...$scoredMovie, '_identity_valid' => false], $scoredTv]), 'Invalid identity evidence must not be released by synopsis ordering.');
assertSameValue(null, $select->invoke($plugin, [[...$scoredMovie, '_identity_score' => 75.0]]), 'The minimum identity score remains mandatory.');

// Exercise the complete candidate-search/details path, not just the selector.
$service = new CandidateTmdbService(
    tvCandidates: [$tv], movieCandidates: [$movie],
    tvDetails: [93001 => normalizedTvDetailsFixture(93001, $title, $tv['overview'])],
    movieDetails: [93002 => normalizedMovieDetailsFixture(93002, $title, $movie['overview'])],
);
$compound = null;
$episodePath = null;
$result = $search->invokeArgs($plugin, [$service, $title, null, null, $description, &$compound, null, null, &$episodePath, 'de-DE']);
assertSameValue([93002, 'movie'], [$result['tmdb_id'] ?? null, $result['_media_type'] ?? null], 'The real bounded search must hydrate the movie selected by stronger synopsis evidence.');
assertSameValue([1, 1, 0, 1], [$service->tvCandidateSearches, $service->movieCandidateSearches, $service->tvDetailsRequests, $service->movieDetailsRequests], 'The synopsis winner requires no extra rival detail lookup.');

echo "TMDB synopsis tie tests passed.\n";
