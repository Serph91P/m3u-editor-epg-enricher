# EPG Enricher Plugin

Enriches EPG programme data with artwork, genres and descriptions from TMDB. Only processes channels that are actually mapped in your playlists, complementing existing Schedules Direct / Gracenote metadata where data is missing.

## Features

- **Playlist-scoped** - Only enriches channels mapped in your playlists, not the entire EPG source
- **TMDB Artwork** - Adds poster and backdrop images to programmes missing artwork
- **Genre/Category** - Fills in missing programme genres from TMDB data
- **Descriptions** - Adds missing programme descriptions/overviews from TMDB
- **EPG Category Mapping** - Maps genres to compact Emby compatible categories or detailed Kodi guide color genres from m3u-editor issue 347
- **Gracenote-aware** - Respects existing Schedules Direct / Gracenote metadata and only fills gaps
- **Title Cache** - Caches TMDB lookups to disk to avoid redundant API calls across runs

## Requirements

- TMDB API key configured in M3U Editor general settings
- M3U Editor plugin API 1.0.0, with the `EpgCacheEnrichmentService` capability from [host PR #1556](https://github.com/m3ue/m3u-editor/pull/1556) deployed before this plugin version is enabled
- At least one EPG source with a valid cache
- Channels mapped to EPG channels in at least one playlist

## Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Enrich from TMDB | On | Fetch posters, genres and descriptions from TMDB |
| Overwrite existing | Off | Replace existing metadata even if already present (e.g. from Schedules Direct) |
| Enrich categories | On | Fill missing genre/category from TMDB |
| Enrich descriptions | On | Fill missing descriptions from TMDB |
| Map genres to Emby compatible categories | Off | Normalize genres to compact Emby-style categories (Movie, Series, News, Sports, Kids, Documentary, Music, Education) |
| Map genres to Kodi guide color genres | Off | Normalize genres to the detailed Kodi labels from m3u-editor issue 347 (Basketball, Public Affairs, Comedy-Drama, Science Fiction, Cooking, Game Show, etc.). This takes precedence over the Emby compatible mapping when both are enabled. |
| Auto-run on cache | On | After a related EPG cache is generated, enrich all EPG sources used by each eligible selected playlist |

### Compatibility behavior

| Emby compatible | Kodi detailed | Category output |
|-----------------|---------------|-----------------|
| Off | Off | Standard XMLTV. Preserve the first source or TMDB genre without client-specific normalization. |
| On | Off | Compact Emby compatible categories. |
| Off | On | Detailed Kodi guide color genres. |
| On | On | Detailed Kodi guide color genres. Kodi takes precedence. |

Jellyfin, Plex, TiviMate, m3u-tv, and other clients use Standard XMLTV because no verified plugin output difference requires another setting. Programme identity and artwork ordering remain globally correct XMLTV behavior rather than client profile behavior. Keyword category detection is active when either existing category mapper is enabled.

## How It Works

1. After the EPG cache is generated (hook: `epg.cache.generated`), or when triggered manually
2. Resolves every EPG source and channel ID used by each eligible selected playlist
3. Reads bounded, canonical programme snapshots from the host, but only processes targeted channels
4. For each programme missing artwork/genres/descriptions:
   - Checks the local TMDB title cache
   - If not cached, validates movie/TV identity independently of artwork, with TV-first probing for episodic or film/special-plus-subtitle rows
   - Fetches full details (poster, backdrop, genres, overview)
   - Reads bounded pages using the host's integer cursor and submits conditional `id`/`hash` patches; the host publishes accepted changes
5. Programmes that already have metadata (e.g. from Schedules Direct / Gracenote) are skipped unless "Overwrite existing" is enabled
6. Upgrade sequence: deploy the host capability first, then enable this plugin release. The manifest API version is 1.0.0 because the host validator requires an exact match; this does not imply that a pre-#1556 1.0.0 host provides the enrichment service. The plugin fails without that service and never uses direct cache access. This repository does not claim live server, XMLTV-consumer, or Emby validation.

## Identity matching and host compatibility

Matching uses Unicode casefold/NFC without transliteration or accent removal. Only explicit trailing work-year markers are extracted; a valid structured work year takes priority. Episode production years are never TV-premiere filters. The minimum identity score (76) and ordinary winner margin (8) are unchanged. Artwork, popularity and result rank do not break identity ties.

Hosts exposing `searchIdentityCandidates` provide `complete`/`incomplete`/`error` plus canonical candidates. The plugin expands a cached first page to at most four native pages / 80 candidates per media type, query and year; incomplete or failed cohorts abstain. `getIdentityAlternativeTitles` and `getIdentityTranslations` provide ID-bound, validated aliases with explicit failure status, at most 200 names per endpoint, cached for an hour. Native alias checks cover every collected candidate before selection so a later translated rival is not hidden by an earlier exact name. Detail rescoring hydrates at most two namesakes; episode disambiguation refuses cohorts larger than ten rather than inspecting only part of them. EPG-channel display names can add positive network evidence, never a negative veto; their source/channel binding is included in the identity cache.

An MV/SP provider marker with a typed subtitle is not strong TV evidence. After complete TV searches establish no viable TV identity, the plugin may search the **full work title plus subtitle** as a movie, requiring an exact complete title and additional work-year or synopsis evidence. TV ambiguity, failed/partial responses, a missing poster or a stale TV ID cannot authorize this change. A valid TV identity remains TV. Existing hosts retain their bounded top-ten or legacy single-result APIs; without explicit native completeness they do not enable this new media-type fallback. No direct plugin search HTTP client is introduced.

For native cross-repository HTTP regressions, set `EPG_ENRICHER_SOURCE` to the checked-out `Plugin.php` when running Core's `tests/Feature/TmdbIdentitySearchTest.php`; the optional plugin cases otherwise skip, while native host tests still run. Tests use `Http::fake` and reject stray requests.

## Artwork roles, XMLTV and clients

The plugin submits canonical artwork through the host API. The host accepts its canonical `poster`, `banner`, `fanart`, and `logo` image types; plugin `backdrop` and `screenshot` candidates are normalized to `fanart` and `banner` respectively before submission. The host owns cache persistence and serialization; channel logos remain channel metadata. A backdrop does not satisfy an enabled poster requirement. A qualified programme poster is preferred for `programme.icon`; backdrops and exact episode stills remain separate secondary roles. Without either overwrite switch, an existing nonempty primary icon is preserved. `Replace existing artwork` enables image replacement without overwriting existing descriptions or categories.

Image fetching, ranking and episode overviews use the effective plugin TMDB locale (falling back to the host setting). Image cache keys include that locale and the enrichment logic version; season caches are locale-specific. If the preferred/neutral/English image response contains no qualified poster, the plugin makes at most one unfiltered request for other available languages. Missing posters are reused only within the current invocation, not persisted as a durable image-cache miss.

New posters require portrait geometry from the matched work's image metadata (dimensions or an explicit aspect ratio). A details URL alone does not establish geometry and is not published as a made-up 500×750 poster. Known square, landscape or contradictory poster metadata is rejected; existing protected provider/legacy artwork remains unchanged without overwrite. Production-year evidence comes from a title year, a structured work year or an explicit production header/label, not an arbitrary year in the plot. Independently established series identity can supply series artwork even when episode numbering differs or a season is unavailable; a conflicting episode subtitle cannot supply episode text or stills. Ambiguous works continue to abstain.

Run data distinguishes `programmes_already_enriched` from `programmes_unmatched` and `programmes_missing_artwork` (the latter two may overlap). These describe accepted output and are corrected after cache replay; an unmatched lookup is not a lookup skip. A stale first page retains one bounded retry. If a later page becomes stale, the run fails explicitly and must be retried from the beginning, because the legacy host API cannot prove that earlier pages survived a store rebuild.

When effective artwork overwrite is off, the plugin preserves an existing valid scalar Primary without promoting secondary imagery. Hosts advertising `preserved_primary_url` in snapshot `supported_fields` receive this URL-bound selection metadata, including explicit clearing on overwrite or scalar changes. Older hosts receive no unknown patch field. Supporting Core exports carry it as optional JSON `preserved_primary_url` or an empty `<?m3ue-preserved-primary?>` processing instruction immediately before the selected XMLTV scalar icon; the latter is an application extension, not a standard XMLTV role or attribute. Backdrop/still roles remain independent.

For the current host export, `programme.icon` remains the generic `<icon>` fallback for icon-only XMLTV consumers. Plugin-only changes cannot make an arbitrary consumer read image roles it never imports. The pending host API contract is [m3u-editor PR #1556](https://github.com/m3ue/m3u-editor/pull/1556); no live server, XMLTV-consumer, or Emby validation is claimed here.

TMDB vote fields rank otherwise eligible images but do not prove content identity or visual suitability. A geometrically valid unvoted backdrop is retained with explicit `tmdb_metadata_unrated` provenance rather than being reported as a positively-rated selection. A client can still crop, cache, or ignore a role after a correct feed has been exported; that is distinct from an enrichment failure.

## Version History

### 1.9.0
- **Breaking**: Removed TheSportsDB integration. SportsDB enrichment is now a separate plugin: [m3u-editor-epg-sportsdb](https://github.com/Serph91P/m3u-editor-epg-sportsdb)
- Removed settings: "Enrich sport events from TheSportsDB", "TheSportsDB API key", "TheSportsDB country filter"

### 1.8.1
- Fixed confusing statistics: replaced "programmes skipped" with clearer "programmes processed", "programmes updated", "programmes already enriched"
- Fixed duplicate log entries in Activity Stream
- Fixed rename error when cache directory is removed during enrichment

### 1.8.0
- Added TheSportsDB integration for sport programme enrichment (now moved to separate plugin)
- Added smart TMDB matching with title splitting and result validation
- Added keyword-based category detection for Sports, News, Kids, Documentary

### 1.7.0
- Added configurable enrichment toggles for posters, backdrops, categories, descriptions
- All enrichment features individually toggleable via boolean settings

### 1.6.0
- Added playlist filter for auto-run: choose which playlists should be enriched automatically (leave empty for all)

### 1.5.1
- Fixed invalid setting type for TMDB language (use `text` instead of `string`)

### 1.5.0
- Added TMDB search language setting to improve matching for non-English programmes (e.g. German shows like "Galileo Stories", "GRIP - Das Motormagazin")
- Set to `de-DE` for German EPG sources, `fr-FR` for French, etc. Leave empty to use the global setting.

### 1.4.1
- Added `repository` field to plugin.json for automatic update checking

### 1.4.0
- Added Emby EPG genre color mapping: normalizes TMDB genres (English + German) to Emby-compatible categories (News, Sports, Kids, Movie, Series, Documentary, Music, Education)
- New setting "Map Emby genres" (off by default) - also remaps existing categories from other sources

### 1.1.0
- **Breaking**: Removed gap filling feature (use the built-in "Enable dummy EPG" in playlist settings instead)
- Enrichment now scoped to playlist channels only (not the entire EPG source)
- Respects existing Schedules Direct / Gracenote metadata
- Tracks skipped (non-playlist) programmes in stats

### 1.0.2
- Fixed plugin validation (settings field IDs, data_ownership structure)

### 1.0.1
- Added missing `queue_jobs` permission

### 1.0.0
- Initial release
