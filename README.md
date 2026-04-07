# EPG Enricher Plugin

Enriches EPG programme data with artwork, genres and descriptions from TMDB. Only processes channels that are actually mapped in your playlists, complementing existing Schedules Direct / Gracenote metadata where data is missing.

## Features

- **Playlist-scoped** - Only enriches channels mapped in your playlists, not the entire EPG source
- **TMDB Artwork** - Adds poster and backdrop images to programmes missing artwork
- **Genre/Category** - Fills in missing programme genres from TMDB data
- **Descriptions** - Adds missing programme descriptions/overviews from TMDB
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
