#!/usr/bin/env python3
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "release-commit-metadata.sh"
WORKFLOW = ROOT / ".github" / "workflows" / "release.yml"


class ReleaseMetadataTest(unittest.TestCase):
    def run_helper(self, mode: str, subjects: list[str]) -> str:
        result = subprocess.run(
            [str(SCRIPT), mode],
            input="\n".join(subjects) + "\n",
            text=True,
            capture_output=True,
            check=True,
        )
        return result.stdout.strip()

    def test_classifies_prefixed_and_plain_conventional_subjects(self) -> None:
        cases = {
            "patch": [
                "fix: plain fix",
                "[verified] fix: verified fix",
            ],
            "minor": [
                "feat(ui): plain feature",
                "[verified] feat(api): verified feature",
            ],
            "major": [
                "feat!: plain breaking feature",
                "[verified] feat(api)!: verified breaking feature",
            ],
        }

        for expected, subjects in cases.items():
            for subject in subjects:
                with self.subTest(subject=subject):
                    self.assertEqual(expected, self.run_helper("classify", [subject]))

    def test_preserves_bump_precedence_for_mixed_subjects(self) -> None:
        self.assertEqual(
            "major",
            self.run_helper(
                "classify",
                [
                    "[verified] fix: patch",
                    "feat: minor",
                    "[verified] refactor(core)!: major",
                ],
            ),
        )
        self.assertEqual(
            "minor",
            self.run_helper(
                "classify",
                ["fix: patch", "[verified] feat: minor"],
            ),
        )
        self.assertEqual("none", self.run_helper("classify", ["release notes only"]))

    def test_normalizes_only_the_optional_verification_marker(self) -> None:
        subjects = [
            "[verified] fix: verified fix",
            "feat: plain feature",
            "[verifiedly] fix: unrelated prefix",
        ]
        self.assertEqual(
            "\n".join(
                [
                    "fix: verified fix",
                    "feat: plain feature",
                    "[verifiedly] fix: unrelated prefix",
                ]
            ),
            self.run_helper("normalize", subjects),
        )

    def test_workflow_keeps_manual_override_and_uses_helper_for_changelog(self) -> None:
        workflow = WORKFLOW.read_text()
        manual = workflow.index('MANUAL="${{ inputs.bump }}"')
        classify = workflow.index("scripts/release-commit-metadata.sh classify")
        changelog = workflow.index("- name: Generate changelog")
        normalize = workflow.index("scripts/release-commit-metadata.sh normalize")

        self.assertLess(manual, classify)
        self.assertLess(changelog, normalize)
        self.assertIn('echo "type=$MANUAL" >> "$GITHUB_OUTPUT"', workflow)
        self.assertIn("exit 0", workflow[manual:classify])


if __name__ == "__main__":
    unittest.main()
