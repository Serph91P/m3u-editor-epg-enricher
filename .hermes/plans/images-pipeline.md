# EPG-Enricher: Images-Pipeline + Year + Miss-Logging

> **For Hermes:** Use subagent-driven-development skill to implement this plan task-by-task.

**Goal:** Plugin liefert pro Programme mehrere artwork-varianten (poster, backdrop, logo, still) in unterschiedlichen größen/sprachen damit jeder client passend rendern kann; titel-jahr wird für besseren TMDB-match extrahiert; verfehlte titel werden geloggt für späteres tuning.

**Architecture:** Erweitert `enrichProgrammeFromTmdb()` in `Plugin.php`. Neue private helper für TMDB `/movie/{id}/images` + `/tv/{id}/images` calls (eigenes Http::get da TmdbService keinen public getter hat - api_key via `app(\\App\\Settings\\GeneralSettings::class)->tmdb_api_key`). Bilder-cache als zweite cache-datei `tmdb-images-cache.json` (per tmdb_id+type, niemals per titel - bilder ändern sich kaum). Year-extraction in `extractBaseTitle()` integriert (rückgabe-shape erweitert auf `[base, year]` oder via callable signature-änderung). Miss-log appendet jsonl unter `plugin-data/epg-enricher/missed-titles.jsonl` und neue health_check-aktion liest top-N raus.

**Tech Stack:** PHP 8.5, Laravel `Http::`, hauptapp services (`TmdbService`, `GeneralSettings`), keine neuen composer-deps.

**Branch:** `feature/images-pipeline` (bereits angelegt, basiert auf `main` @ a948956).

---

## Vorab-context (lies das BEVOR du loslegst)

- Repo: `~/Dokumente/private projekte/m3u-editor-epg-enricher`
- Einziges relevantes file: `Plugin.php` (~1691 LOC)
- Hauptapp-XMLTV-output (read-only ref): `~/Dokumente/private projekte/m3u-editor/app/Http/Controllers/EpgGenerateController.php` Z.347-364 - erwartet `$programme['images'][i]` mit keys: `url, type, width, height, orient, size`. **type** kann frei sein (poster/backdrop/screenshot/logo) - wird nur als attribut emittiert.
- TmdbService base url: `https://api.themoviedb.org/3`, image base: `https://image.tmdb.org/t/p/{size}`
- TMDB image-sizes: posters `w92, w154, w185, w342, w500, w780, original` · backdrops `w300, w780, w1280, original` · logos `w45, w92, w154, w185, w300, w500, original` · stills `w92, w185, w300, original`
- Sprache aus settings: `$settings['tmdb_language'] ?? 'de-DE'` (siehe Plugin.php Z.1593 `setTmdbLanguage`)
- **WICHTIG**: kein `php artisan test`, kein pest im plugin. Manuelle verifizierung via dispatch + lesen der JSONL output.
- **Kein** test-framework eingerichtet - TDD entfällt. Stattdessen: kleine **smoke-scripts** unter `scripts/` mit `php scripts/smoke-images.php` und manuelle EPG-runs.
- **Commits**: nach jedem task. Single-line `-m '…'` (multi-line bricht im terminal).
- **Pint**: `cd ../m3u-editor && vendor/bin/pint --dirty ../m3u-editor-epg-enricher/Plugin.php` (plugin hat selbst kein pint - hauptapp-binary nutzen).

---

## Task 1: Year-extraction in extractBaseTitle

**Objective:** Wenn der titel ein jahr enthält (`Inception (2010)`, `Avatar - 2009`), wird das jahr abgespalten und an TMDB-search übergeben für deutlich bessere matches bei häufigen titeln (z.B. „The Office US 2005" vs UK 2001).

**Files:** Modify `Plugin.php` (`extractBaseTitle` Z.1231, `searchTmdbWithValidation` Z.1263, `enrichProgrammeFromTmdb` Z.846-872)

**Step 1: Erweitere `extractBaseTitle` um optional year-rückgabe**

Ändere signatur auf `private function extractBaseTitle(string $title): array` mit return `['title' => string, 'year' => ?int]`. Year-regex: `/\b(19\d{2}|20\d{2})\b/` - match das letzte vorkommen.

**Step 2: Update alle 2 callsites in `enrichProgrammeFromTmdb`**

Z.849 wird:
```php
$baseExtracted = $this->extractBaseTitle($title);
$baseTitle = $baseExtracted['title'];
$year = $baseExtracted['year'];
```

**Step 3: Erweitere `searchTmdbWithValidation` um year-param**

Signatur:
```php
private function searchTmdbWithValidation(TmdbService $tmdb, string $searchTitle, ?string $forceMediaType = null, ?int $year = null): ?array
```
Reiche `$year` an `$tmdb->searchTvSeries($searchTitle, $year)` und `$tmdb->searchMovie($searchTitle, $year)` durch.

**Step 4: Reiche year an callsites**

Z.868, 872 → vierter param `$year`.

**Step 5: Cache-key um year erweitern**

`$cacheSuffix = ($forcedMediaType ? "|{$forcedMediaType}" : '') . ($year ? "|y{$year}" : '');`

**Step 6: Smoke-test**

```bash
cd ~/Dokumente/private\ projekte/m3u-editor-epg-enricher
php -r "require 'Plugin.php'; \$r = (new ReflectionClass('AppLocalPlugins\\\\EpgEnricher\\\\Plugin'))->getMethod('extractBaseTitle'); \$r->setAccessible(true); \$p = new \\AppLocalPlugins\\EpgEnricher\\Plugin(); var_dump(\$r->invoke(\$p, 'Inception (2010)'));"
```
Expected: array `['title' => 'Inception', 'year' => 2010]`

**Step 7: Pint + commit**

```bash
cd ~/Dokumente/private\ projekte/m3u-editor && vendor/bin/pint --dirty
cd ~/Dokumente/private\ projekte/m3u-editor-epg-enricher
git add Plugin.php && git commit -m 'feat: extract year from title for better TMDB match'
```

---

## Task 2: Helper getTmdbApiKey()

**Objective:** Ein zentraler getter für api_key + language, damit Task 3+4 nicht GeneralSettings rumreichen müssen.

**Files:** Modify `Plugin.php`

**Step 1: Füge methode VOR `setTmdbLanguage` (Z.1593) ein**

```php
/**
 * Resolve TMDB api credentials from GeneralSettings.
 * Returns null if not configured (caller should skip image fetch silently).
 *
 * @return array{key: string, language: string}|null
 */
private function getTmdbCredentials(): ?array
{
    try {
        $settings = app(\App\Settings\GeneralSettings::class);
        $key = trim((string) ($settings->tmdb_api_key ?? ''));
        if ($key === '') {
            return null;
        }
        $language = $settings->tmdb_language ?? 'de-DE';
        return ['key' => $key, 'language' => $language];
    } catch (\Throwable $e) {
        Log::warning('[EpgEnricher] Failed to resolve TMDB credentials', ['error' => $e->getMessage()]);
        return null;
    }
}
```

**Step 2: Verifizieren - pint + commit**

```bash
git add Plugin.php && git commit -m 'feat: add getTmdbCredentials helper'
```

---

## Task 3: Images-cache laden/speichern

**Objective:** Eigener cache für `/images` responses unter `plugin-data/epg-enricher/tmdb-images-cache.json`. Key = `"{media_type}:{tmdb_id}"`. Spart api-calls bei wiederholten enrichment-runs.

**Files:** Modify `Plugin.php`

**Step 1: Suche existierende cache-helpers**

`loadTmdbCache` Z.1069, `saveTmdbCache` Z.1103 - kopiere das pattern.

**Step 2: Füge zwei neue methoden direkt nach `saveTmdbSeasonCache` (Z.1117) ein**

```php
private function loadTmdbImagesCache(): array
{
    $path = storage_path('app/plugin-data/epg-enricher/tmdb-images-cache.json');
    if (! file_exists($path)) {
        return [];
    }
    $raw = file_get_contents($path);
    if ($raw === false) {
        return [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

private function saveTmdbImagesCache(array $cache): void
{
    $dir = storage_path('app/plugin-data/epg-enricher');
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $path = $dir . '/tmdb-images-cache.json';
    @file_put_contents($path, json_encode($cache, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
}
```

**Step 3: commit**

```bash
git add Plugin.php && git commit -m 'feat: add tmdb images cache load/save'
```

---

## Task 4: TMDB images-endpoint fetch

**Objective:** Neue private methode die `/movie/{id}/images` oder `/tv/{id}/images` ruft, mit `include_image_language=<lang>,en,null` damit auch sprachneutrale assets (logos!) zurückkommen. Liefert ein normalisiertes array `{posters:[…], backdrops:[…], logos:[…]}` wo jeder eintrag `{file_path, width, height, vote_average, iso_639_1, aspect_ratio}` enthält.

**Files:** Modify `Plugin.php`

**Step 1: Methode direkt nach `saveTmdbImagesCache` einfügen**

```php
/**
 * Fetch /movie/{id}/images or /tv/{id}/images from TMDB.
 *
 * @return array{posters: array, backdrops: array, logos: array}|null
 */
private function fetchTmdbImages(int $tmdbId, string $mediaType, array &$cache): ?array
{
    $cacheKey = "{$mediaType}:{$tmdbId}";
    if (array_key_exists($cacheKey, $cache)) {
        return $cache[$cacheKey];
    }

    $creds = $this->getTmdbCredentials();
    if ($creds === null) {
        return null;
    }

    $endpoint = $mediaType === 'movie' ? "movie/{$tmdbId}/images" : "tv/{$tmdbId}/images";
    $shortLang = explode('-', $creds['language'])[0] ?? 'en';

    try {
        $response = Http::timeout(15)->get(
            "https://api.themoviedb.org/3/{$endpoint}",
            [
                'api_key' => $creds['key'],
                // null = sprach-neutrale assets (wichtig für logos)
                'include_image_language' => "{$shortLang},en,null",
            ]
        );

        if (! $response->successful()) {
            $cache[$cacheKey] = null;
            return null;
        }

        $data = $response->json();
        $result = [
            'posters' => is_array($data['posters'] ?? null) ? $data['posters'] : [],
            'backdrops' => is_array($data['backdrops'] ?? null) ? $data['backdrops'] : [],
            'logos' => is_array($data['logos'] ?? null) ? $data['logos'] : [],
        ];
        $cache[$cacheKey] = $result;
        return $result;
    } catch (\Throwable $e) {
        Log::warning('[EpgEnricher] TMDB images fetch failed', [
            'tmdb_id' => $tmdbId,
            'media_type' => $mediaType,
            'error' => $e->getMessage(),
        ]);
        $cache[$cacheKey] = null;
        return null;
    }
}
```

**Step 2: Stelle sicher dass `use Illuminate\Support\Facades\Http;` und `use Illuminate\Support\Facades\Log;` oben drin sind** (sind sie laut grep schon - falls nicht: ergänzen)

**Step 3: Smoke-test (manuell, ohne setup-zwang)**

Skip wenn kein TMDB key verfügbar - Task 5 testet end-to-end.

**Step 4: commit**

```bash
git add Plugin.php && git commit -m 'feat: fetch TMDB images endpoint with multi-language assets'
```

---

## Task 5: Image-set selection helper

**Objective:** Aus dem rohen TMDB-images-array die "besten" N varianten pro typ wählen. Regeln:
- **Posters** (orient=P): top 2 (eine in user-sprache wenn vorhanden, eine sprach-neutral oder en als fallback) - größe `w500` (500x750 typical)
- **Backdrops** (orient=L): top 2 - sprach-neutral bevorzugt - größe `w1280` (1280x720)
- **Logos** (orient=L, transparent): top 1 - sprach-präferenz user-lang→en→null - größe `w500`

Sortier-kriterium: `vote_average` desc, dann `vote_count` desc.

**Files:** Modify `Plugin.php`

**Step 1: Methode direkt nach `fetchTmdbImages` einfügen**

```php
/**
 * Select the best image variants per type from a TMDB images response.
 * Returns programme-ready image entries (url + type + width + height + orient + size).
 *
 * @param array{posters: array, backdrops: array, logos: array} $images
 * @return array<int, array{url: string, type: string, width: int, height: int, orient: string, size: int}>
 */
private function selectImageSet(array $images, string $userLang): array
{
    $shortLang = explode('-', $userLang)[0] ?? 'en';
    $out = [];

    $rank = function (array $img, array $langPriority): int {
        $iso = $img['iso_639_1'] ?? null;
        foreach ($langPriority as $idx => $code) {
            if ($iso === $code || ($code === null && $iso === null)) {
                return $idx;
            }
        }
        return count($langPriority);
    };

    $sortBy = function (array $a, array $b, array $langPriority) use ($rank): int {
        $ra = $rank($a, $langPriority);
        $rb = $rank($b, $langPriority);
        if ($ra !== $rb) return $ra <=> $rb;
        // Höhere vote_average zuerst
        return ($b['vote_average'] ?? 0) <=> ($a['vote_average'] ?? 0);
    };

    // Posters: user-lang bevorzugt
    $posters = $images['posters'] ?? [];
    $langPrioPoster = [$shortLang, 'en', null];
    usort($posters, fn ($a, $b) => $sortBy($a, $b, $langPrioPoster));
    foreach (array_slice($posters, 0, 2) as $p) {
        if (empty($p['file_path'])) continue;
        $out[] = [
            'url' => 'https://image.tmdb.org/t/p/w500' . $p['file_path'],
            'type' => 'poster',
            'width' => 500,
            'height' => (int) round(500 / max($p['aspect_ratio'] ?? 0.667, 0.1)),
            'orient' => 'P',
            'size' => 2,
        ];
    }

    // Backdrops: sprach-neutral bevorzugt
    $backdrops = $images['backdrops'] ?? [];
    $langPrioBack = [null, $shortLang, 'en'];
    usort($backdrops, fn ($a, $b) => $sortBy($a, $b, $langPrioBack));
    foreach (array_slice($backdrops, 0, 2) as $b) {
        if (empty($b['file_path'])) continue;
        $out[] = [
            'url' => 'https://image.tmdb.org/t/p/w1280' . $b['file_path'],
            'type' => 'backdrop',
            'width' => 1280,
            'height' => (int) round(1280 / max($b['aspect_ratio'] ?? 1.778, 0.1)),
            'orient' => 'L',
            'size' => 3,
        ];
    }

    // Logos: user-lang→en→null
    $logos = $images['logos'] ?? [];
    $langPrioLogo = [$shortLang, 'en', null];
    usort($logos, fn ($a, $b) => $sortBy($a, $b, $langPrioLogo));
    foreach (array_slice($logos, 0, 1) as $l) {
        if (empty($l['file_path'])) continue;
        $out[] = [
            'url' => 'https://image.tmdb.org/t/p/w500' . $l['file_path'],
            'type' => 'logo',
            'width' => 500,
            'height' => (int) round(500 / max($l['aspect_ratio'] ?? 2.5, 0.1)),
            'orient' => 'L',
            'size' => 1,
        ];
    }

    return $out;
}
```

**Step 2: commit**

```bash
git add Plugin.php && git commit -m 'feat: select best images per type with language priority'
```

---

## Task 6: Integration in enrichProgrammeFromTmdb

**Objective:** Nach erfolgreichem TMDB-match die images-pipeline rufen, dedupliziert in `$programme['images']` mergen. Steuerung via existierende `$enrichPosters` / `$enrichBackdrops` flags + neue setting `enrich_logos` (default true wenn enrichBackdrops true).

**Files:** Modify `Plugin.php` (`enrichProgrammeFromTmdb` Z.771ff, `doEnrich` für cache-init)

**Step 1: cache initialisieren in `doEnrich` (Z.331)**

Such die zeile wo `$cache = $this->loadTmdbCache();` steht und ergänze:
```php
$imagesCache = $this->loadTmdbImagesCache();
```
…und ans ende von `doEnrich` (vor return) `$this->saveTmdbImagesCache($imagesCache);`.

**Step 2: cache-param an `enrichProgrammeFromTmdb` durchreichen**

Signatur erweitern um `array &$imagesCache,` als letzten param. Auch in der callsite (in `processDateFile`) param ergänzen.

**Step 3: Nach den existierenden poster/backdrop-blöcken (nach Z.938) neuen block einfügen**

```php
// Erweiterte images-pipeline: hole zusätzliche varianten + logo
if (($enrichPosters || $enrichBackdrops) && ! empty($tmdbData['tmdb_id']) && ! empty($mediaType)) {
    $creds = $this->getTmdbCredentials();
    $imageSet = $this->fetchTmdbImages((int) $tmdbData['tmdb_id'], $mediaType, $imagesCache);
    if ($imageSet !== null && $creds !== null) {
        $candidates = $this->selectImageSet($imageSet, $creds['language']);
        $existingUrls = array_column($programme['images'] ?? [], 'url');
        foreach ($candidates as $img) {
            if (in_array($img['url'], $existingUrls, true)) {
                continue;
            }
            // Respect type-flags
            if ($img['type'] === 'poster' && ! $enrichPosters) continue;
            if ($img['type'] === 'backdrop' && ! $enrichBackdrops) continue;
            // logos sind opt-in via enrichBackdrops (L-orient assets)
            if ($img['type'] === 'logo' && ! $enrichBackdrops) continue;

            $programme['images'][] = $img;
            $existingUrls[] = $img['url'];
            $result['changed'] = true;
        }
    }
}
```

**Step 4: end-to-end smoke-test**

```bash
cd ~/Dokumente/private\ projekte/m3u-editor
php artisan tinker
# in tinker:
# > $plugin = app(\AppLocalPlugins\EpgEnricher\Plugin::class);
# > $plugin->runAction('health_check', [], app(\App\Plugins\PluginExecutionContext::class, ['plugin_id' => 'epg-enricher']));
```
Bzw. realistisch: einen kleinen EPG-run dispatchen via filament-UI oder `php artisan epg:enrich` falls action dafür existiert. Dann inspizieren:
```bash
ls storage/app/plugin-data/epg-enricher/
# erwarte: tmdb-cache.json, tmdb-season-cache.json, tmdb-images-cache.json
```
Prüfe ein generated JSONL programme ob `images[]` jetzt 3-5 einträge mit `type: poster|backdrop|logo` hat.

**Step 5: pint + commit**

```bash
cd ~/Dokumente/private\ projekte/m3u-editor && vendor/bin/pint --dirty
cd ~/Dokumente/private\ projekte/m3u-editor-epg-enricher
git add Plugin.php && git commit -m 'feat: integrate multi-variant images pipeline (poster/backdrop/logo)'
```

---

## Task 7: Miss-logging

**Objective:** Wenn `searchTmdbWithValidation` `null` liefert (also TMDB-match gescheitert), den titel + extracted base + year in `plugin-data/epg-enricher/missed-titles.jsonl` appenden. Aggregation via neuer healthCheck-section "top missed titles".

**Files:** Modify `Plugin.php`

**Step 1: Neue methode nach `saveTmdbImagesCache`**

```php
private function logMissedTitle(string $title, string $baseTitle, ?int $year, ?string $forcedMediaType): void
{
    $dir = storage_path('app/plugin-data/epg-enricher');
    if (! is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $line = json_encode([
        'ts' => date('c'),
        'title' => $title,
        'base' => $baseTitle,
        'year' => $year,
        'forced_type' => $forcedMediaType,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    @file_put_contents($dir . '/missed-titles.jsonl', $line . "\n", FILE_APPEND | LOCK_EX);
}
```

**Step 2: Call in `enrichProgrammeFromTmdb` direkt im `if (! $tmdbData)` block (~Z.888)**

```php
if (! $tmdbData) {
    if (! $result['cache_hit']) {
        $this->logMissedTitle($title, $baseTitle, $year, $forcedMediaType);
    }
    // … bestehender code
}
```

**Step 3: Erweitere `healthCheck` (Z.1013) um "top missed titles" section**

Lies das jsonl, count titel-occurrences, gib top 20 zurück. (Aufwand max 30 zeilen - siehe healthCheck struktur, append an `$context->log` oder result-data wie der bestehende code es macht.)

**Step 4: commit**

```bash
git add Plugin.php && git commit -m 'feat: log missed TMDB matches + expose top-missed in health check'
```

---

## Task 8: Final review + push

**Step 1: Diff anschauen**

```bash
cd ~/Dokumente/private\ projekte/m3u-editor-epg-enricher
git log --oneline main..HEAD
git diff main..HEAD --stat
```

**Step 2: Pint final**

```bash
cd ~/Dokumente/private\ projekte/m3u-editor && vendor/bin/pint
```

**Step 3: Push**

```bash
cd ~/Dokumente/private\ projekte/m3u-editor-epg-enricher
git push -u origin feature/images-pipeline
```

**Step 4: User informieren** dass branch gepusht ist und einmal manuell ein EPG-enrichment-run laufen lassen soll um JSONL output in `plugin-data/m3u-editor/epg-cache/<epg_id>/<date>.jsonl` zu inspizieren. Erwartung: pro programme jetzt 3-5 image-einträge mit unterschiedlichen typen.

---

## Pitfalls

1. **`$programme['images']` darf nicht überschrieben werden** - der bestehende code (Z.910, 926, 985) appendet schon. Neuer code muss auch appenden + dedupliziert per URL.
2. **TMDB rate-limit** - `Http::timeout(15)` reicht, aber bei vielen unique tmdb_ids in einem run kann TMDB throttlen (50 req / 1s war historisch). Wenn smoke-test 429 zeigt → in `fetchTmdbImages` ein `usleep(100000)` (100ms) einfügen.
3. **Kein api_key konfiguriert** → `getTmdbCredentials()` returnt null → Task 4 returnt null → integration in Task 6 skip silently. Kein crash, kein log-spam. Korrekt.
4. **JSONL append concurrency** - `LOCK_EX` reicht für single-process enricher. Bei parallelen runs theoretisch race aber unkritisch (logfile, kein authoritative state).
5. **Logos sind transparent PNGs** - clients die `<icon>` ohne `type`-attribut auswerten sehen das logo evtl. als haupt-icon und rendern es schlecht. Bestehender code setzt `$programme['icon']` aus poster (Z.903) - das **darf nicht** auf logo umgestellt werden. Logo nur via `images[]`.
6. **`aspect_ratio` von TMDB** ist die echte ratio (width/height). Wenn fehlend (alte einträge) → fallback nutzen, sonst division-by-zero.
7. **`mediaType`** kommt aus `$tmdbData['_media_type']` (gesetzt in `searchTmdbWithValidation` Z.1287/1312) - kann theoretisch fehlen wenn cache aus alter version. Defensiv check.
8. **Pint im plugin-repo** funktioniert nicht (kein composer setup) - immer hauptapp-pint von `~/Dokumente/private projekte/m3u-editor` aus auf den plugin-pfad anwenden.
