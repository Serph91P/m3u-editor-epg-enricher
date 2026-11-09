<?php

namespace AppLocalPlugins\EpgEnricher\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class IdentityTmdbService extends \App\Services\TmdbService
{
    /**
     * Complete identity-search evidence, separate from the legacy top-N API.
     * At most four native pages / eighty candidates; no related-resource calls.
     *
     * @return array{status: string, candidates: list<array<string, mixed>>}
     */
    public function searchIdentityCandidates(string $mediaType, string $title, ?int $year = null, int $maxPages = 4): array
    {
        $error = ['status' => 'error', 'candidates' => []];
        if (! $this->isConfigured() || ! in_array($mediaType, ['tv', 'movie'], true)) {
            return $error;
        }
        // Preserve meaningful year tokens and full compound titles. The older
        // search normalizer intentionally strips these for interactive lookups.
        $query = \Normalizer::normalize(mb_convert_case(trim($title), MB_CASE_FOLD, 'UTF-8'), \Normalizer::FORM_C);
        $query = preg_replace('/\s+/u', ' ', $query);
        if ($query === '') {
            return $error;
        }
        $maxPages = max(1, min(4, $maxPages));
        $fields = $mediaType === 'tv'
            ? ['name', 'original_name', 'first_air_date', 'overview']
            : ['title', 'original_title', 'release_date', 'overview'];
        $candidates = [];
        $totalPages = null;
        $totalResults = null;
        try {
            for ($page = 1; $page <= $maxPages; $page++) {
                $params = ['api_key' => $this->apiKey, 'query' => $query, 'language' => $this->language, 'include_adult' => false, 'page' => $page];
                if ($year !== null) {
                    $params[$mediaType === 'tv' ? 'first_air_date_year' : 'year'] = $year;
                }
                $cacheKey = 'tmdb_identity_page_v1_'.hash('sha256', json_encode([$mediaType, $params], JSON_THROW_ON_ERROR));
                $payload = Cache::get($cacheKey);
                if ($payload === null) {
                    $this->waitForRateLimit();
                    $response = Http::timeout(15)->get(self::BASE_URL."/search/{$mediaType}", $params);
                    if (! $response->successful()) {
                        return $error;
                    }
                    $payload = $response->json();
                }
                if (! is_array($payload) || ($payload['page'] ?? null) !== $page
                    || ! is_int($payload['total_pages'] ?? null) || $payload['total_pages'] < 0
                    || ! is_int($payload['total_results'] ?? null) || $payload['total_results'] < 0
                    || ! is_array($payload['results'] ?? null) || ! array_is_list($payload['results'])
                    || ($payload['total_pages'] !== (int) ceil($payload['total_results'] / 20)
                        && ! ($page === 1 && $payload['total_results'] === 0 && $payload['total_pages'] === 1))
                    || count($payload['results']) !== min(20, max(0, $payload['total_results'] - ($page - 1) * 20))
                    || ($totalPages !== null && $totalPages !== $payload['total_pages'])
                    || ($totalResults !== null && $totalResults !== $payload['total_results'])) {
                    return $error;
                }
                $totalPages = $payload['total_pages'];
                $totalResults = $payload['total_results'];
                foreach ($payload['results'] as $result) {
                    if (! is_array($result) || ! is_int($result['id'] ?? null) || $result['id'] <= 0) {
                        return $error;
                    }
                    $candidate = ['tmdb_id' => $result['id']];
                    foreach ($fields as $field) {
                        if (! array_key_exists($field, $result) || ($result[$field] !== null && ! is_string($result[$field]))) {
                            return $error;
                        }
                        $candidate[$field] = $result[$field];
                    }
                    if (trim((string) $candidate[$fields[0]]) === '' && trim((string) $candidate[$fields[1]]) === '') {
                        return $error;
                    }
                    $candidates[$result['id']] ??= $candidate;
                }
                Cache::put($cacheKey, $payload, 3600);
                if ($page >= $totalPages) {
                    return ['status' => 'complete', 'candidates' => array_values($candidates)];
                }
            }
        } catch (\Throwable) {
            return $error;
        }

        return ['status' => 'incomplete', 'candidates' => array_values($candidates)];
    }

    /** @return array{status: string, titles: list<array{title: string, iso_3166_1: string}>} */
    public function getIdentityAlternativeTitles(string $mediaType, int $tmdbId): array
    {
        $error = ['status' => 'error', 'titles' => []];
        if (! $this->isConfigured() || ! in_array($mediaType, ['tv', 'movie'], true) || $tmdbId <= 0) {
            return $error;
        }
        $key = 'tmdb_identity_aliases_v1_'.hash('sha256', json_encode([$this->apiKey, $mediaType, $tmdbId]));
        if (is_array($cached = Cache::get($key))) {
            return $cached;
        }
        try {
            $this->waitForRateLimit();
            $response = Http::timeout(15)->get(self::BASE_URL."/{$mediaType}/{$tmdbId}/alternative_titles", ['api_key' => $this->apiKey]);
            $payload = $response->json();
            $field = $mediaType === 'tv' ? 'results' : 'titles';
            if (! $response->successful() || ! is_array($payload) || ($payload['id'] ?? null) !== $tmdbId
                || ! is_array($payload[$field] ?? null) || ! array_is_list($payload[$field]) || count($payload[$field]) > 200) {
                return $error;
            }
            $titles = [];
            foreach ($payload[$field] as $entry) {
                if (! is_array($entry) || ! is_string($entry['title'] ?? null) || trim($entry['title']) === ''
                    || ! is_string($entry['iso_3166_1'] ?? null) || preg_match('/^[A-Z]{2}$/D', $entry['iso_3166_1']) !== 1) {
                    return $error;
                }
                $titles[] = ['title' => $entry['title'], 'iso_3166_1' => $entry['iso_3166_1']];
            }
            $result = ['status' => 'complete', 'titles' => $titles];
            Cache::put($key, $result, 3600);

            return $result;
        } catch (\Throwable) {
            return $error;
        }
    }

    /** @return array{status: string, titles: list<string>} */
    public function getIdentityTranslations(string $mediaType, int $tmdbId): array
    {
        $error = ['status' => 'error', 'titles' => []];
        if (! $this->isConfigured() || ! in_array($mediaType, ['tv', 'movie'], true) || $tmdbId <= 0) {
            return $error;
        }
        $key = 'tmdb_identity_translations_v1_'.hash('sha256', json_encode([$this->apiKey, $mediaType, $tmdbId]));
        $cached = Cache::get($key);
        if (is_array($cached)) {
            return $cached;
        }
        try {
            $this->waitForRateLimit();
            $response = Http::timeout(15)->get(self::BASE_URL."/{$mediaType}/{$tmdbId}/translations", ['api_key' => $this->apiKey]);
            $payload = $response->json();
            if (! $response->successful() || ! is_array($payload) || ($payload['id'] ?? null) !== $tmdbId
                || ! is_array($payload['translations'] ?? null) || ! array_is_list($payload['translations'])
                || count($payload['translations']) > 200) {
                return $error;
            }
            $titles = [];
            foreach ($payload['translations'] as $translation) {
                $field = $mediaType === 'tv' ? 'name' : 'title';
                if (! is_array($translation) || ! is_string($translation['iso_639_1'] ?? null)
                    || preg_match('/^[a-z]{2}$/D', $translation['iso_639_1']) !== 1
                    || ! is_string($translation['iso_3166_1'] ?? null)
                    || preg_match('/^[A-Z]{2}$/D', $translation['iso_3166_1']) !== 1
                    || ! is_array($translation['data'] ?? null) || ! is_string($translation['data'][$field] ?? null)) {
                    return $error;
                }
                $title = trim($translation['data'][$field]);
                if ($title !== '') {
                    $titles[] = $title;
                }
            }
            $result = ['status' => 'complete', 'titles' => array_values(array_unique($titles))];
            Cache::put($key, $result, 3600);

            return $result;
        } catch (\Throwable) {
            return $error;
        }
    }

}
