#!/usr/bin/env python3
import json
import re
from pathlib import Path

root = Path(__file__).resolve().parents[1]
plugin = (root / "Plugin.php").read_text()
manifest = json.loads((root / "plugin.json").read_text())


def assert_true(condition: bool, message: str) -> None:
    if not condition:
        raise AssertionError(message)


assert_true(
    "mapToKodiGuideGenre(" in plugin,
    "Plugin should expose a dedicated Kodi guide genre mapper.",
)
assert_true(
    "KODI_GUIDE_GENRE_MAP" in plugin,
    "Plugin should keep Kodi guide genres separate from canonical EPG categories.",
)
assert_true(
    "map_genres_to_kodi_guide_genres" in plugin,
    "Kodi guide genre setting should flow into processing code.",
)

for expected in [
    "'basketball' => 'Basketball'",
    "'sports event' => 'Sports Event'",
    "'politics' => 'Public Affairs'",
    "'comedy' => 'Comedy'",
    "'documentary' => 'Documentary'",
    "'animation' => 'Animation'",
    "'science fiction' => 'Science Fiction'",
    "'cooking' => 'Cooking'",
    "'game show' => 'Game Show'",
]:
    assert_true(expected in plugin, f"Missing Kodi mapping: {expected}")

settings = []
for section in manifest["settings"]:
    settings.extend(field["id"] for field in section.get("fields", []))

assert_true(
    "map_genres_to_epg_categories" in settings,
    "Emby compatible category option should remain available.",
)
assert_true(
    "map_genres_to_kodi_guide_genres" in settings,
    "Kodi guide genre option should be a separate setting.",
)

hash_body = re.search(r"private function computeSettingsHash\(array \$settings\): string\s*\{(?P<body>.*?)\n    \}", plugin, re.S)
assert_true(hash_body is not None, "computeSettingsHash should exist.")
assert hash_body is not None
assert_true(
    "map_genres_to_kodi_guide_genres" in hash_body.group("body"),
    "Kodi guide genre setting should invalidate enrichment state.",
)

print("Genre mapping source tests passed.")
