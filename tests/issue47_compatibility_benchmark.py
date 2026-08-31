#!/usr/bin/env python3
"""Verify the redacted Issue 47 baseline and run its offline serialization contract."""

import argparse
import gzip
import hashlib
import json
import subprocess
from pathlib import Path


COMPRESSED_SHA256 = "1b06baa4e3c5387200b4aa7ebd98f15607b1fce5d2d2eee62753b85a28de45b3"
DECOMPRESSED_SHA256 = "c16fd2ff8496faae65d349faff72a3fc5225d662b2062514bec392fb72730436"


def sha256(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--baseline", required=True, type=Path)
    args = parser.parse_args()

    compressed = args.baseline.read_bytes()
    if sha256(compressed) != COMPRESSED_SHA256:
        raise SystemExit("Issue 47 compressed baseline SHA-256 mismatch.")
    decompressed = gzip.decompress(compressed)
    if sha256(decompressed) != DECOMPRESSED_SHA256:
        raise SystemExit("Issue 47 decompressed baseline SHA-256 mismatch.")

    baseline = json.loads(decompressed)
    baseline_parity = baseline["metrics"]["first_last_boundary_parity"]
    if baseline_parity != {"denominator": 101, "numerator": 0}:
        raise SystemExit("Issue 47 baseline first/last metric changed unexpectedly.")

    root = Path(__file__).resolve().parent.parent
    command = ["php", "tests/TmdbArtworkRepairTest.php"]
    runs = [subprocess.run(command, cwd=root, check=True, capture_output=True).stdout for _ in range(2)]
    if runs[0] != runs[1]:
        raise SystemExit("Issue 47 synthetic benchmark output is not deterministic.")

    try:
        fixture_measurement = json.loads(runs[0].splitlines()[-1])
        current_parity = fixture_measurement["first_last_boundary_parity"]
        quality_evidence = fixture_measurement["artwork_quality_evidence"]
        branch_b_evidence = fixture_measurement["branch_b_artwork_evidence"]
        fixture_egress = fixture_measurement["fixture_egress"]
    except (IndexError, json.JSONDecodeError, KeyError, TypeError) as error:
        raise SystemExit("Issue 47 synthetic benchmark did not emit fixture measurements.") from error

    if not isinstance(current_parity, dict) or not all(
        isinstance(current_parity.get(key), int) for key in ("denominator", "numerator")
    ):
        raise SystemExit("Issue 47 synthetic benchmark emitted an invalid parity measurement.")
    if not isinstance(fixture_egress, int):
        raise SystemExit("Issue 47 synthetic benchmark emitted an invalid fixture egress measurement.")
    if quality_evidence != {
        "rejected_zero_vote_title_cards": 1,
        "selected_scene_controls": 1,
    }:
        raise SystemExit("Issue 47 artwork-quality evidence changed unexpectedly.")
    if branch_b_evidence != {
        "canonical_details_preferred": 1,
        "source_primary_lookup_evaluated": 1,
        "reason_codes": ["tmdb_details_backdrop_preferred"],
    }:
        raise SystemExit("Issue 47 Branch B artwork evidence changed unexpectedly.")

    print(json.dumps({
        "baseline_first_last_boundary_parity": baseline_parity,
        "current_first_last_boundary_parity": current_parity,
        "deterministic_repeat_output": True,
        "wrong_identity_regressions": {"status": "unobservable"},
        "wrong_primary_regressions": {
            "status": "source_primary_preserved_after_tmdb_evaluation",
            "canonical_details_preferred": branch_b_evidence["canonical_details_preferred"],
            "source_primary_lookup_evaluated": branch_b_evidence["source_primary_lookup_evaluated"],
        },
        "unsafe_primary_regressions": {
            "status": "no_unsuitable_primary_promoted",
            "rejected_zero_vote_title_cards": quality_evidence["rejected_zero_vote_title_cards"],
            "selected_scene_controls": quality_evidence["selected_scene_controls"],
        },
        "fixture_egress": fixture_egress,
    }, sort_keys=True))


if __name__ == "__main__":
    main()
