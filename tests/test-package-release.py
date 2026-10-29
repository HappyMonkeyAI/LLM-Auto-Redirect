#!/usr/bin/env python3
"""Tests for the allowlisted WordPress plugin release archive."""

import importlib.util
import re
import tempfile
import unittest
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PACKAGE_SCRIPT = ROOT / "scripts" / "package_release.py"
SPEC = importlib.util.spec_from_file_location("package_release", PACKAGE_SCRIPT)
if SPEC is None or SPEC.loader is None:
    raise RuntimeError("Could not load release package builder")
PACKAGE_RELEASE = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(PACKAGE_RELEASE)


class PackageReleaseTests(unittest.TestCase):
    def test_archive_contains_only_the_allowlisted_install_files(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            archive_path = PACKAGE_RELEASE.build_release(Path(temp_dir))

            with zipfile.ZipFile(archive_path) as archive:
                self.assertEqual(
                    set(PACKAGE_RELEASE.RELEASE_FILES),
                    {name.split("/", 1)[1] for name in archive.namelist()},
                )
                self.assertTrue(
                    all(name.startswith("llm-auto-redirect/") for name in archive.namelist())
                )
                self.assertFalse(any("screenshots" in name for name in archive.namelist()))
                self.assertFalse(any("tests/" in name for name in archive.namelist()))

    def test_archive_filename_tracks_plugin_version(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            archive_path = PACKAGE_RELEASE.build_release(Path(temp_dir))
            version = PACKAGE_RELEASE.read_version()
            self.assertEqual(f"llm-auto-redirect-{version}.zip", archive_path.name)

    def test_archive_is_byte_reproducible(self):
        with tempfile.TemporaryDirectory() as temp_dir:
            root = Path(temp_dir)
            first = PACKAGE_RELEASE.build_release(root / "first")
            second = PACKAGE_RELEASE.build_release(root / "second")
            self.assertEqual(first.read_bytes(), second.read_bytes())

    def test_metadata_and_license_agree(self):
        source = (ROOT / "LLM_Auto_Redirect.php").read_text(encoding="utf-8")
        license_text = (ROOT / "LICENSE.md").read_text(encoding="utf-8")
        self.assertIsNotNone(re.search(r"^\s*\*\s+License:\s+MIT\s*$", source, re.MULTILINE))
        self.assertIn("MIT License", license_text)


if __name__ == "__main__":
    unittest.main()
