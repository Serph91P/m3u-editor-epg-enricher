<?php

putenv('TMDB_FIXTURES_ONLY=1');
require __DIR__.'/TmdbArtworkRepairTest.php';

use App\Services\CandidateTmdbService;
use App\Services\TmdbService;
use App\Settings\GeneralSettings;
use AppLocalPlugins\EpgEnricher\Plugin;

$GLOBALS['tmdbTestSettings'] = new GeneralSettings;
$plugin = new Plugin;
$enrich = new ReflectionMethod($plugin, 'enrichProgrammeFromTmdb');
$search = new ReflectionMethod($plugin, 'searchTmdbWithValidation');
$normalize = new ReflectionMethod($plugin, 'normalizeIdentityText');
$key = new ReflectionMethod($plugin, 'normalizeCacheKey');
$similarity = new ReflectionMethod($plugin, 'titleMatchScore');
$base = new ReflectionMethod($plugin, 'extractBaseTitle');
$failures = [];
function assertGlobal(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message.' Expected '.var_export($expected, true).' got '.var_export($actual, true));
    }
}
function checkGlobal(string $name, callable $check): void
{
    global $failures;
    try {
        $check();
        echo "PASS {$name}\n";
    } catch (Throwable $e) {
        $failures[] = $name;
        echo "FAIL {$name}: {$e->getMessage()}\n";
    }
}
checkGlobal('unicode canonical casefold in identity similarity and cache', function () use ($plugin, $normalize, $key, $similarity, $enrich) {
    foreach ([['Straße im Winter', 'STRASSE IM WINTER'], ['ΟΣ Κόσμος', 'οσ κόσμος'], ["Cafe\u{0301} Überfahrt", 'CAFÉ ÜBERFAHRT'], ['Йога ДОМ', "И\u{0306}ОГА дом"], ["कि\u{0930}ण", 'किरण']] as [$a, $b]) {
        assertGlobal($normalize->invoke($plugin, $a), $normalize->invoke($plugin, $b), 'Canonical casefold.');
        assertGlobal($key->invoke($plugin, $a), $key->invoke($plugin, $b), 'Same cache normalization.');
        assertGlobal(1.0, $similarity->invoke($plugin, $a, $b), 'Exact folded title score.');
        $candidate = ['tmdb_id' => 991001, 'title' => $b, 'original_title' => $b, 'release_date' => '2022-01-01', 'overview' => ''];
        $service = new CandidateTmdbService(movieCandidates: [$candidate], movieDetails: [991001 => Tests\normalizedMovieDetailsFixture(991001, $b)]);
        $programme = ['title' => $a];
        $cache = [];
        assertGlobal(true, Tests\enrich($plugin, $enrich, $programme, $service, $cache)['provider_match'], 'Real matcher must accept canonical identity.');
    }
    foreach ([['Café', 'Cafe'], ['東京 2', '東京 3'], ['किरण', 'करण'], ['Åland', 'Aland']] as [$a, $b]) {
        assertGlobal(false, $normalize->invoke($plugin, $a) === $normalize->invoke($plugin, $b), 'Do not erase accents, marks, numbers or scripts.');
        assertGlobal(false, $key->invoke($plugin, $a) === $key->invoke($plugin, $b), 'Distinct cache identities.');
    }
});
checkGlobal('year tokens in a work title survive', function () use ($plugin, $base) {
    foreach (['Synthetic 1984', '2001 Synthetic Odyssey', 'Synthetic 2020 Return'] as $title) {
        assertGlobal(['title' => $title, 'year' => null], $base->invoke($plugin, $title), 'Bare year is part of title.');
    }
    foreach (['Synthetic 1984 (2022)', 'Synthetic 1984 - 2022'] as $title) {
        assertGlobal(['title' => 'Synthetic 1984', 'year' => 2022], $base->invoke($plugin, $title), 'Only explicit trailing marker supplies year.');
    }
});
checkGlobal('explicit work year overrides title marker without filtering episode start', function () use ($plugin, $enrich) {
    $title = 'Synthetic Return';
    $candidates = [];
    $details = [];
    foreach ([991101 => 1982, 991102 => 2022] as $id => $year) {
        $candidates[] = ['tmdb_id' => $id, 'title' => $title, 'original_title' => $title, 'release_date' => $year.'-01-01', 'overview' => ''];
        $details[$id] = Tests\normalizedMovieDetailsFixture($id, $title);
    }
    $service = new CandidateTmdbService(movieCandidates: $candidates, movieDetails: $details);
    $programme = ['title' => $title.' (1982)', 'production_year' => 2022];
    $cache = [];
    assertGlobal(true, Tests\enrich($plugin, $enrich, $programme, $service, $cache)['provider_match'], 'Explicit year resolves remake.');
    assertGlobal([2022], $service->movieYears, 'Explicit production year wins.');
    assertGlobal([991102], $service->movieDetailIds, 'Only confirmed remake details.');
    foreach ([9999, 2099, 'invalid'] as $invalidYear) {
        $service = new CandidateTmdbService(movieCandidates: $candidates, movieDetails: $details);
        $programme = ['title' => $title.' (2022)', 'production_year' => $invalidYear];
        $cache = [];
        assertGlobal(true, Tests\enrich($plugin, $enrich, $programme, $service, $cache)['provider_match'], 'Invalid explicit metadata cannot suppress a valid work marker.');
        assertGlobal([2022], $service->movieYears, 'Only a valid explicit work year has priority.');
    }
    $service = new CandidateTmdbService;
    $programme = ['title' => 'Synthetic Episode (2022)', 'production_year' => 2023, 'episode_nums' => [['system' => 'xmltv_ns', 'value' => '1.2.']]];
    $cache = [];
    Tests\enrich($plugin, $enrich, $programme, $service, $cache);
    assertGlobal([null], array_values(array_unique($service->tvYears, SORT_REGULAR)), 'Episode year cannot filter series start.');
});
class GlobalCandidateTmdbService extends CandidateTmdbService
{
    public array $identityResponses = [];

    public array $translations = [];

    public array $identityRequests = [];

    public array $translationRequests = [];

    public function searchIdentityCandidates(string $type, string $title, ?int $year = null, int $maxPages = 4): array
    {
        $this->identityRequests[] = [$type, $title, $year, $maxPages];

        return $this->identityResponses[$type.':'.$title] ?? ['status' => 'complete', 'candidates' => []];
    }

    public function getIdentityTranslations(string $type, int $id): array
    {
        $this->translationRequests[] = [$type, $id];

        return $this->translations[$id] ?? ['status' => 'complete', 'titles' => []];
    }
}
checkGlobal('late candidates are evaluated but late equal competitors and incomplete searches abstain', function () use ($plugin, $search) {
    $title = 'Synthetic Distant Harbor';
    $candidates = [];
    for ($i = 1; $i <= 33; $i++) {
        $name = $i === 33 ? $title : 'Unrelated Fiction '.$i;
        $candidates[] = ['tmdb_id' => 993000 + $i, 'name' => $name, 'original_name' => $name, 'first_air_date' => '2020-01-01', 'overview' => ''];
    }
    $service = new GlobalCandidateTmdbService(tvDetails: [993033 => Tests\normalizedTvDetailsFixture(993033, $title)]);
    $service->identityResponses['tv:'.$title] = ['status' => 'complete', 'candidates' => $candidates];
    assertGlobal(993033, Tests\validatedSearch($plugin, $search, $service, $title, 'tv')['tmdb_id'] ?? null, 'A late exact candidate needs the same validation.');
    $service->identityResponses['tv:'.$title]['candidates'][0] = array_replace($candidates[32], ['tmdb_id' => 993001]);
    assertGlobal(null, Tests\validatedSearch($plugin, $search, $service, $title, 'tv'), 'Late equal competitor cannot disappear.');
    foreach (['error', 'incomplete'] as $status) {
        $service->identityResponses['tv:'.$title] = ['status' => $status, 'candidates' => [$candidates[32]]];
        assertGlobal(null, Tests\validatedSearch($plugin, $search, $service, $title, 'tv'), 'Incomplete evidence is not a unique winner.');
    }
});
checkGlobal('provider translations establish full aliases without merging subseries', function () use ($plugin, $search) {
    $title = 'Synthetic World Atlas';
    $candidates = [['tmdb_id' => 994001, 'name' => 'Unrelated Localized Name', 'original_name' => '別の作品', 'first_air_date' => '2020-01-01', 'overview' => '']];
    $service = new GlobalCandidateTmdbService(tvDetails: [994001 => Tests\normalizedTvDetailsFixture(994001, $candidates[0]['name'])]);
    $service->identityResponses['tv:'.$title] = ['status' => 'complete', 'candidates' => $candidates];
    $service->translations[994001] = ['status' => 'complete', 'titles' => [$title]];
    assertGlobal(994001, Tests\validatedSearch($plugin, $search, $service, $title, 'tv')['tmdb_id'] ?? null, 'Native translation title supplies exact work evidence.');
    $service->translations[994001] = ['status' => 'complete', 'titles' => [$title.' History']];
    assertGlobal(null, Tests\validatedSearch($plugin, $search, $service, $title, 'tv'), 'A prefix does not prove a subseries.');
    $service->translations[994001] = ['status' => 'complete', 'titles' => [$title]];
    $service->translations[994002] = ['status' => 'complete', 'titles' => [$title]];
    $service->identityResponses['tv:'.$title]['candidates'][] = array_replace($candidates[0], ['tmdb_id' => 994002]);
    assertGlobal(null, Tests\validatedSearch($plugin, $search, $service, $title, 'tv'), 'Ambiguous translations abstain.');
});
checkGlobal('same-channel network evidence resolves namesakes without deciding common-network ties', function () use ($plugin, $enrich) {
    $title = 'Synthetic Observatory';
    $candidates = [];
    $details = [];
    foreach ([995001 => 'Synthetic North', 995002 => 'Synthetic South'] as $id => $network) {
        $candidates[] = ['tmdb_id' => $id, 'name' => $title, 'original_name' => $title, 'first_air_date' => '2020-01-01', 'overview' => ''];
        $details[$id] = Tests\normalizedTvDetailsFixture($id, $title) + ['networks' => [['id' => $id, 'name' => $network, 'logo' => null]]];
    }
    $service = new GlobalCandidateTmdbService(tvDetails: $details);
    $service->identityResponses['tv:'.$title] = ['status' => 'complete', 'candidates' => $candidates];
    $cache = [];
    foreach ([['Synthetic North', 995001], ['Synthetic South', 995002], ['Unrelated Sender', null], ['', null], ['Synthetic North', 995001]] as [$channel,$expected]) {
        $programme = ['title' => $title, 'channel' => $channel];
        $before = count($service->tvDetailIds);
        $r = Tests\enrich($plugin, $enrich, $programme, $service, $cache, lookupContext: ['epg_source_id' => 'synthetic', 'channel_names' => $channel === '' ? [] : [$channel]]);
        assertGlobal($expected !== null, $r['provider_match'], 'Network is positive additional evidence, missing or mismatching names cannot decide a tie.');
        if ($expected !== null && ! $r['cache_hit']) {
            assertGlobal($expected, end($cache)['tmdb_id'], 'Correct same-channel network identity.');
        }
    }
    $details[995002]['networks'] = $details[995001]['networks'];
    $service = new GlobalCandidateTmdbService(tvDetails: $details);
    $service->identityResponses['tv:'.$title] = ['status' => 'complete', 'candidates' => $candidates];
    $programme = ['title' => $title, 'channel' => 'same'];
    $cache = [];
    assertGlobal(false, Tests\enrich($plugin, $enrich, $programme, $service, $cache, lookupContext: ['channel_names' => ['Synthetic North']])['provider_match'], 'Shared network does not break a tie.');
});
checkGlobal('episode tokens and production years never become series premiere filters on either host', function () use ($plugin, $enrich) {
    $legacy = new class('offline') extends TmdbService
    {
        public array $years = [];

        public function searchTvSeries(string $name, ?int $year = null): ?array
        {
            $this->years[] = $year;

            return null;
        }
    };
    $title = 'Synthetic Lantern';
    $candidates = [];
    foreach ([996001 => 2019, 996002 => 2021] as $id => $year) {
        $candidates[] = ['tmdb_id' => $id, 'name' => $title, 'original_name' => $title, 'first_air_date' => $year.'-01-01', 'overview' => ''];
    }
    $modern = new CandidateTmdbService(tvCandidates: $candidates);
    foreach ([$legacy, $modern] as $service) {
        $cache = [];
        foreach ([2019, 2021] as $year) {
            foreach (['Episode '.$year, 'Episode ('.$year.')'] as $episode) {
                $programme = ['title' => $title.' - '.$episode, 'subtitle' => $episode, 'production_year' => $year, 'desc' => 'Production year: '.$year, 'episode_num' => '1.1'];
                $r = Tests\enrich($plugin, $enrich, $programme, $service, $cache);
                assertGlobal(false, $r['provider_match'], 'No invented remake distinction from episode dates.');
            }
        }
        assertGlobal([null], array_values(array_unique($service === $legacy ? $legacy->years : $modern->tvYears, SORT_REGULAR)), 'No episode year reaches first_air_date_year.');
        assertGlobal([], $cache, 'Ambiguous episode years cannot create shared confirmed bindings.');
    }
});
checkGlobal('movie and special rows resolve separate full-title films only after complete unconfirmed TV', function () use ($plugin, $enrich) {
    $series = 'Synthetic Ridge - A Coastal Mystery';
    $synopsis = 'Cartographers uncover vanished treasure beneath ancient lighthouse';
    $service = new GlobalCandidateTmdbService;
    // Details are supplied through the ordinary host, not poster availability.
    $details = [];
    foreach ([997001 => 'Broken Compass', 997002 => 'Hidden Signal'] as $id => $subtitle) {
        $title = $series.' - '.$subtitle;
        $candidate = ['tmdb_id' => $id, 'title' => $title, 'original_title' => $title, 'release_date' => '2022-01-01', 'overview' => $synopsis];
        $details[$id] = array_replace(Tests\normalizedMovieDetailsFixture($id, $title, $synopsis, null, 'https://image.tmdb.org/t/p/original/film-'.$id.'.jpg'), ['release_date' => '2022-01-01']);
        $service->identityResponses['movie:'.$title] = ['status' => 'complete', 'candidates' => [$candidate]];
    }
    $host = new GlobalCandidateTmdbService(movieDetails: $details);
    $host->identityResponses = $service->identityResponses;
    $cache = [];
    foreach ([['MV123', 'Broken Compass', 997001], ['SP124', 'Hidden Signal', 997002], ['MV123', 'Broken Compass', 997001]] as [$provider,$subtitle,$id]) {
        $programme = ['title' => $series, 'subtitle' => $subtitle, 'episode_num' => $provider, 'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.0.']], 'desc' => $synopsis, 'production_year' => 2022, 'tmdb_id' => 997999];
        $r = Tests\enrich($plugin, $enrich, $programme, $host, $cache);
        assertGlobal(true, $r['provider_match'], 'A film row needs full work and subtitle plus independent evidence.');
        assertGlobal('https://image.tmdb.org/t/p/original/film-'.$id.'.jpg', $programme['icon'] ?? null, 'Subtitles and cache replay must not share a series binding.');
        if (! $r['cache_hit']) {
            assertGlobal('movie', end($cache)['_media_type'], 'Film identity is explicit.');
        }
    }
    foreach (['error', 'incomplete'] as $status) {
        $host->identityResponses['tv:'.$series] = ['status' => $status, 'candidates' => []];
        $programme = ['title' => $series, 'subtitle' => 'Broken Compass', 'episode_num' => 'MV123', 'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.0.']], 'desc' => $synopsis];
        $cache = [];
        $before = count(array_filter($host->identityRequests, fn ($r) => $r[0] === 'movie'));
        assertGlobal(false, Tests\enrich($plugin, $enrich, $programme, $host, $cache)['provider_match'], 'Failed TV search cannot change media type.');
        assertGlobal($before, count(array_filter($host->identityRequests, fn ($r) => $r[0] === 'movie')), 'No movie request on incomplete TV evidence.');
    }
    $host->identityResponses = $service->identityResponses;
    $host->identityResponses['tv:'.$series] = ['status' => 'complete', 'candidates' => [['tmdb_id' => 997900, 'name' => 'Unrelated Synthetic Workshop', 'original_name' => 'Unrelated Synthetic Workshop', 'first_air_date' => '2010-01-01', 'overview' => '']]];
    $programme = ['title' => $series, 'subtitle' => 'Broken Compass', 'episode_num' => 'MV123', 'production_year' => 2022];
    $cache = [];
    assertGlobal(true, Tests\enrich($plugin, $enrich, $programme, $host, $cache)['provider_match'], 'A fully evaluated unrelated TV result does not prove a series identity.');
    $host->identityResponses = $service->identityResponses;
    foreach ([['MV123', '', null], ['EP123', $synopsis, null], ['MV123', '', 2022]] as [$provider,$description,$year]) {
        $programme = ['title' => $series, 'subtitle' => 'Broken Compass', 'episode_num' => $provider, 'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.0.']], 'desc' => $description];
        if ($year !== null) {
            $programme['production_year'] = $year;
        }
        $cache = [];
        $r = Tests\enrich($plugin, $enrich, $programme, $host, $cache);
        assertGlobal($provider === 'MV123' && $year === 2022, $r['provider_match'], 'Provider marker alone is not independent work evidence; true EP guard remains.');
    }
});
checkGlobal('an exact primary cannot hide an equally valid translated rival', function () use ($plugin, $search) {
    $title = 'Synthetic Twin Horizon';
    $candidates = [];
    foreach ([998001 => $title, 998002 => '異なる表題'] as $id => $name) {
        $candidates[] = ['tmdb_id' => $id, 'name' => $name, 'original_name' => $name, 'first_air_date' => '2020-01-01', 'overview' => ''];
    }
    $host = new GlobalCandidateTmdbService(tvDetails: [998001 => Tests\normalizedTvDetailsFixture(998001, $title)]);
    $host->identityResponses['tv:'.$title] = ['status' => 'complete', 'candidates' => $candidates];
    $host->translations[998002] = ['status' => 'complete', 'titles' => [$title]];
    assertGlobal(null, Tests\validatedSearch($plugin, $search, $host, $title, 'tv'), 'No primary-name preference over a verified exact translated identity.');
});
checkGlobal('movie provider and subtitle alone are not strong TV signals', function () use ($plugin) {
    $detect = new ReflectionMethod($plugin, 'detectSeriesSignals');
    foreach (['MV123', 'SP123'] as $provider) {
        $signals = $detect->invoke($plugin, ['title' => 'Synthetic Ridge', 'subtitle' => 'Broken Compass', 'episode_num' => $provider]);
        assertGlobal('medium', $signals['confidence'], 'A film marker is not an episodic provider ID.');
    }
});
checkGlobal('episode detail budget cannot hide an unexamined equal rival', function () use ($plugin, $enrich) {
    $title = 'Synthetic Long Cohort';
    $candidates = [];
    $seasons = [];
    for ($i = 1; $i <= 11; $i++) {
        $candidates[] = ['tmdb_id' => 999000 + $i, 'name' => $title, 'original_name' => $title, 'first_air_date' => '2020-01-01', 'overview' => ''];
        $seasons[(999000 + $i).':1'] = $i === 1 ? ['status' => 'found', 'payload' => ['season_number' => 1, 'episodes' => [['episode_number' => 1, 'name' => 'Synthetic Pilot']]]] : ['status' => 'not_found'];
    }
    $host = new GlobalCandidateTmdbService(tvDetails: [999001 => Tests\normalizedTvDetailsFixture(999001, $title)], seasonResults: $seasons);
    $host->identityResponses['tv:'.$title] = ['status' => 'complete', 'candidates' => $candidates];
    $cache = [];
    $programme = ['title' => $title, 'subtitle' => 'Synthetic Pilot', 'episode_num' => 'EP123', 'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.0.']]];
    assertGlobal(false, Tests\enrich($plugin, $enrich, $programme, $host, $cache)['provider_match'], 'Over-budget rivals are unresolved, not absent.');
    assertGlobal(0, $host->seasonRequests, 'Do not start a partial episode cohort.');
});
checkGlobal('confirmed TV and ambiguous or faulty TV details cannot be replaced by a film', function () use ($plugin, $enrich) {
    $title = 'Synthetic Ridge';
    $full = $title.' - Broken Compass';
    $tv = ['tmdb_id' => 997701, 'name' => $title, 'original_name' => $title, 'first_air_date' => '2020-01-01', 'overview' => ''];
    foreach (['valid', 'ambiguous', 'removed', 'wrong-id'] as $mode) {
        $details = $mode === 'removed' ? [] : [997701 => Tests\normalizedTvDetailsFixture($mode === 'wrong-id' ? 997799 : 997701, $title)];
        $host = new GlobalCandidateTmdbService(tvDetails: $details, movieDetails: [997001 => Tests\normalizedMovieDetailsFixture(997001, $full)]);
        $host->identityResponses['tv:'.$title] = ['status' => 'complete', 'candidates' => $mode === 'ambiguous' ? [$tv, array_replace($tv, ['tmdb_id' => 997702])] : [$tv]];
        $host->identityResponses['movie:'.$full] = ['status' => 'complete', 'candidates' => [['tmdb_id' => 997001, 'title' => $full, 'original_title' => $full, 'release_date' => '2022-01-01', 'overview' => '']]];
        $programme = ['title' => $title, 'subtitle' => 'Broken Compass', 'episode_num' => 'MV123', 'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.0.']], 'production_year' => 2022];
        $cache = [];
        assertGlobal($mode === 'valid',Tests\enrich($plugin,$enrich,$programme,$host,$cache)['provider_match'],'TV absence must be confirmed, never inferred from failed details or ambiguity.');
        assertGlobal(0,count(array_filter($host->identityRequests,fn ($r) => $r[0] === 'movie')),'No film fallback from a valid TV identity, missing poster, wrong details or ambiguous TV.');
    }
});
checkGlobal('validated film identities publish only their qualified portrait as Primary', function () use ($plugin, $enrich) {
    $GLOBALS['tmdbTestSettings']->tmdb_api_key = 'synthetic-offline';
    try {
        foreach ([997001 => 'Broken Compass', 997002 => 'Hidden Signal'] as $id => $subtitle) {
            $title = 'Synthetic Ridge - '.$subtitle;
            $host = new GlobalCandidateTmdbService(movieDetails: [$id => array_replace(Tests\normalizedMovieDetailsFixture($id, $title), ['release_date' => '2022-01-01'])]);
            $host->identityResponses['movie:'.$title] = ['status' => 'complete', 'candidates' => [['tmdb_id' => $id, 'title' => $title, 'original_title' => $title, 'release_date' => '2022-01-01', 'overview' => '']]];
            Illuminate\Support\Facades\Http::$responses = [new Illuminate\Support\Facades\FakeHttpResponse(true, ['posters' => [['file_path' => '/synthetic-film-'.$id.'.jpg', 'iso_639_1' => null, 'width' => 1000, 'height' => 1500]], 'backdrops' => [], 'logos' => []])];
            $programme = ['title' => 'Synthetic Ridge', 'subtitle' => $subtitle, 'episode_num' => 'MV123', 'episode_nums' => [['system' => 'xmltv_ns', 'value' => '0.0.']], 'production_year' => 2022];
            $cache = [];
            assertGlobal(true, Tests\enrich($plugin, $enrich, $programme, $host, $cache)['provider_match'], 'Confirm the film before fetching artwork.');
            assertGlobal('https://image.tmdb.org/t/p/w500/synthetic-film-'.$id.'.jpg', $programme['icon'] ?? null, 'Qualified identity-specific portrait must become Primary.');
            $poster = array_values(array_filter($programme['images'] ?? [], fn ($image) => ($image['type'] ?? null) === 'poster'));
            assertGlobal(500, $poster[0]['width'] ?? null, 'Selected w500 rendition must retain the proven portrait ratio.');
            assertGlobal(750, $poster[0]['height'] ?? null, 'Scale the verified source dimensions to the selected rendition.');
        }
    } finally {
        $GLOBALS['tmdbTestSettings']->tmdb_api_key = '';
    }
});
if ($failures !== []) {
    exit(1);
}
echo "Global identity regressions passed.\n";
