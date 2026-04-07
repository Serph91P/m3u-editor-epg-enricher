# EPG Enricher Plugin

Enriches EPG programme data with artwork, genres and descriptions from TMDB. Optionally fills gaps in programme schedules with placeholder entries.

## Features

- **TMDB Artwork** - Adds poster and backdrop images to programmes missing artwork
- **Genre/Category** - Fills in missing programme genres from TMDB data
- **Descriptions** - Adds missing programme descriptions/overviews from TMDB
- **Gap Filling** - Detects time gaps between programmes and inserts placeholders
- **Title Cache** - Caches TMDB lookups to disk to avoid redundant API calls across runs

## Requirements

- TMDB API key configured in M3U Editor general settings (for artwork enrichment)
- At least one EPG source with a valid cache

## Settings

| Setting | Default | Description |
|---------|---------|-------------|
| Enrich from TMDB | On | Fetch posters, genres and descriptions from TMDB |
| Fill EPG gaps | Off | Insert placeholder entries into schedule gaps |
| Min gap (minutes) | 30 | Gaps shorter than this are ignored |
| Gap placeholder title | "No programme data" | Title for inserted gap entries |
| Overwrite existing | Off | Replace existing artwork even if already present |
| Enrich categories | On | Fill missing genre/category from TMDB |
| Enrich descriptions | On | Fill missing descriptions from TMDB |
| Auto-run on cache | On | Run automatically after EPG cache generation |

## How It Works

1. After the EPG cache is generated (hook: `epg.cache.generated`), or when triggered manually
2. Reads each day's cached programme JSONL files
3. For each programme missing artwork/genres/descriptions:
   - Checks the local TMDB title cache
   - If not cached, searches TMDB (movie first, then TV series)
   - Fetches full details (poster, backdrop, genres, overview)
   - Writes enriched data back to the JSONL cache
4. If gap filling is enabled, inserts placeholder programmes where schedule gaps exceed the threshold
5. The enriched data is used the next time EPG output is generated

## Version History

### 1.0.0
- Initial release
