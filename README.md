# EPG Enricher Plugin

Enriches EPG programme data with artwork, genres and descriptions from TMDB. Only processes channels that are actually mapped in your playlists, complementing existing Schedules Direct / Gracenote metadata where data is missing.

## Features

- **Playlist-scoped** - Only enriches channels mapped in your playlists, not the entire EPG source
- **TMDB Artwork** - Adds poster and backdrop images to programmes missing artwork
- **Genre/Category** - Fills in missing programme genres from TMDB data
- **Descriptions** - Adds missing programme descriptions/overviews from TMDB
- **Emby EPG Colors** - Maps genres to Emby-compatible categories (News, Sports, Kids, Movie, etc.) for color-coded EPG guides
- **Gracenote-aware** - Respects existing Schedules Direct / Gracenote metadata and only fills gaps
- **Title Cache** - Caches TMDB lookups to disk to avoid redundant API calls across runs

## Requirements

- TMDB API key configured in M3U Editor general settings
- At least one EPG source with a valid cache
- Channels mapped to EPG channels in at least one playlist

## Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Enrich from TMDB | On | Fetch posters, genres and descriptions from TMDB |
| Overwrite existing | Off | Replace existing metadata even if already present (e.g. from Schedules Direct) |
| Enrich categories | On | Fill missing genre/category from TMDB |
| Enrich descriptions | On | Fill missing descriptions from TMDB |
| Map Emby genres | Off | Normalize genres to Emby-compatible categories for EPG guide color coding |
| Auto-run on cache | On | Run automatically after EPG cache generation |

## How It Works

1. After the EPG cache is generated (hook: `epg.cache.generated`), or when triggered manually
2. Resolves which EPG channel IDs are actually used in your playlists
3. Reads each day's cached programme JSONL files, but only processes targeted channels
4. For each programme missing artwork/genres/descriptions:
   - Checks the local TMDB title cache
   - If not cached, searches TMDB (movie first, then TV series)
   - Fetches full details (poster, backdrop, genres, overview)
   - Writes enriched data back to the JSONL cache
5. Programmes that already have metadata (e.g. from Schedules Direct / Gracenote) are skipped unless "Overwrite existing" is enabled
6. The enriched data is used the next time EPG output is generated

## Version History

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
