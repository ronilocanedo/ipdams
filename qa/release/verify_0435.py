#!/usr/bin/env python3
"""Read-only v0.2.435 Settings ZIP integrity preflight; never installs anything."""
import hashlib
import json
import sys
import zipfile

EXPECTED_ZIP_SHA256 = "2e56252613177c9914d88e84bc1aa01cf4136ba45b9fcb03e1120302fdf5a799"
EXPECTED_FILES = {
    "app/Bir2307.php": "0d5bc86b69096c98bbb925aa5a25cd8f82379e6b6ca0f661c82c510b8bad7546",
    "public/api/bir2307.php": "4b896cace786ebac19a9a9a716bc4b4875fb2caa6c4e7518a32cb935dc8821c9",
    "public/assets/app.js": "e2912825ca21ec177e931cb0e7512ec936b333573eeb1807a344a8318f74c283",
}

def check(path):
    with open(path, "rb") as f:
        actual = hashlib.file_digest(f, "sha256").hexdigest()
    assert actual == EXPECTED_ZIP_SHA256, f"Unexpected installer SHA-256: {actual}"
    with zipfile.ZipFile(path) as z:
        assert set(z.namelist()) == set(EXPECTED_FILES) | {"release.json"}, "Unexpected ZIP contents"
        release = json.loads(z.read("release.json"))
        assert release["version"] == "0.2.435"
        assert release["from_version"] == "0.2.434"
        assert release["files"] == EXPECTED_FILES
        for name, expected in EXPECTED_FILES.items():
            assert hashlib.sha256(z.read(name)).hexdigest() == expected, f"Hash mismatch: {name}"
    print("PASS: v0.2.435 Settings installer integrity (read-only).")
    print("NOT TESTED: browser integration, production schema, installation.")

if __name__ == "__main__":
    if len(sys.argv) != 2:
        sys.exit("Usage: python qa/release/verify_0435.py PATH_TO_SETTINGS_ZIP")
    check(sys.argv[1])
