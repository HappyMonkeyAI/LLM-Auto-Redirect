#!/usr/bin/env python3
"""Build a minimal, reproducible WordPress plugin ZIP from an explicit allowlist."""

import argparse
import re
import zipfile
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PLUGIN_SLUG = "llm-auto-redirect"
RELEASE_FILES = (
    "LLM_Auto_Redirect.php",
    "README.md",
    "LICENSE.md",
)


def read_version():
    source = (ROOT / "LLM_Auto_Redirect.php").read_text(encoding="utf-8")
    match = re.search(r"^\s*\*\s*Version:\s*([0-9]+\.[0-9]+\.[0-9]+)\s*$", source, re.MULTILINE)
    if not match:
        raise ValueError("Could not read a semantic version from the plugin header")
    return match.group(1)


def validate_release_tree(version):
    missing = [name for name in RELEASE_FILES if not (ROOT / name).is_file()]
    if missing:
        raise FileNotFoundError("Missing release files: " + ", ".join(missing))

    source = (ROOT / "LLM_Auto_Redirect.php").read_text(encoding="utf-8")
    readme = (ROOT / "README.md").read_text(encoding="utf-8")
    license_text = (ROOT / "LICENSE.md").read_text(encoding="utf-8")

    if "License:           MIT" not in source:
        raise ValueError("Plugin header license must match the selected MIT license")
    if not license_text.lstrip().startswith("# MIT License"):
        raise ValueError("LICENSE.md does not declare the MIT License")
    if f"Version {version}" not in readme:
        raise ValueError("README.md version does not match the plugin header")
    if "## License\n\nMIT." not in readme:
        raise ValueError("README.md license statement does not match LICENSE.md")


def build_release(output_dir=None):
    version = read_version()
    validate_release_tree(version)

    output_dir = Path(output_dir) if output_dir is not None else ROOT / "dist"
    output_dir.mkdir(parents=True, exist_ok=True)
    archive_path = output_dir / f"{PLUGIN_SLUG}-{version}.zip"

    with zipfile.ZipFile(archive_path, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as archive:
        for relative_path in RELEASE_FILES:
            info = zipfile.ZipInfo(f"{PLUGIN_SLUG}/{relative_path}", date_time=(1980, 1, 1, 0, 0, 0))
            info.compress_type = zipfile.ZIP_DEFLATED
            info.create_system = 3
            info.external_attr = 0o100644 << 16
            archive.writestr(info, (ROOT / relative_path).read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)

    expected_names = {f"{PLUGIN_SLUG}/{name}" for name in RELEASE_FILES}
    with zipfile.ZipFile(archive_path, "r") as archive:
        actual_names = set(archive.namelist())
    if actual_names != expected_names:
        archive_path.unlink(missing_ok=True)
        raise ValueError("Release archive contents differ from the explicit allowlist")

    return archive_path


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", type=Path, default=ROOT / "dist", help="directory for the generated ZIP")
    args = parser.parse_args()

    archive_path = build_release(args.output_dir)
    print(f"Created release archive: {archive_path.resolve()}")
    print(f"Archive entries: {len(RELEASE_FILES)}")


if __name__ == "__main__":
    main()
