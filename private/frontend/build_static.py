#!/usr/bin/env python3
"""Assemble the reviewed legacy IPDAMS frontend without npm or PHP.

This preserves source bytes, not a React/TypeScript compilation or production deployment.
"""
from __future__ import annotations

import argparse
import hashlib
from html.parser import HTMLParser
import json
from pathlib import Path
import shutil
import tempfile

ROOT = Path(__file__).resolve().parents[2]
ASSETS = ROOT / "assets"
ENTRY = ROOT / "app.html"
NOTICE = ROOT / "private/frontend/THIRD_PARTY_NOTICES.md"
DEFAULT_DEST = ROOT / "private/frontend/dist-static"
PINNED = {
    "react.production.min.js": ("16.0.0", "de59b0d4504aa0350c13775fec9b78859f3eda83dea21d60dded98bb7866d54a"),
    "react-dom.production.min.js": ("16.0.1", "c7a65157caebd9af7e108f1f1eb8b6e2c8fafb141d0f4c2a4d4dfd851c52ec9e"),
}


class AssetRefs(HTMLParser):
    def __init__(self) -> None:
        super().__init__()
        self.paths: list[str] = []
        self.scripts: list[str] = []

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        data = dict(attrs)
        for key in ("src", "href"):
            value = data.get(key)
            if value and value.startswith("assets/"):
                path = value.split("?", 1)[0].split("#", 1)[0]
                self.paths.append(path)
                if tag == "script" and key == "src":
                    self.scripts.append(path)


def digest(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as source:
        for part in iter(lambda: source.read(1024 * 1024), b""):
            h.update(part)
    return h.hexdigest()


def preflight() -> tuple[list[Path], AssetRefs]:
    if not ENTRY.is_file() or ENTRY.is_symlink():
        raise ValueError("Missing or unsafe source app.html")
    if not ASSETS.is_dir() or ASSETS.is_symlink():
        raise ValueError("Missing or unsafe source assets folder")
    if not NOTICE.is_file() or NOTICE.is_symlink():
        raise ValueError("Third-party MIT notice is required")
    paths = sorted(ASSETS.rglob("*"), key=lambda p: p.relative_to(ASSETS).as_posix())
    if not paths:
        raise ValueError("Empty asset source directory")
    source_files: list[Path] = []
    for path in paths:
        if path.is_symlink():
            raise ValueError(f"Symlink forbidden: {path.relative_to(ROOT)}")
        if path.is_dir():
            continue
        if not path.is_file() or path.suffix not in {".css", ".js", ".png", ".jpg", ".jpeg", ".svg", ".ico", ".webp", ".woff2"}:
            raise ValueError(f"Unexpected source asset type: {path.relative_to(ROOT)}")
        source_files.append(path)
    for name, (version, expected) in PINNED.items():
        file = ASSETS / name
        if not file.is_file():
            raise ValueError(f"Missing pinned React runtime: assets/{name}")
        if digest(file) != expected:
            raise ValueError(f"Legacy React runtime checksum mismatch: assets/{name}")
        if f"React v{version}" not in file.read_text("utf-8")[:200]:
            raise ValueError(f"Legacy React runtime header differs: assets/{name}")
    refs = AssetRefs()
    refs.feed(ENTRY.read_text("utf-8"))
    if not refs.paths:
        raise ValueError("app.html has no local asset references")
    for ref in refs.paths:
        if ref.startswith("assets/../") or ".." in Path(ref).parts or not (ROOT / ref).is_file():
            raise ValueError(f"Missing/unsafe asset referenced by app.html: {ref}")
    sequence = ["assets/react.production.min.js", "assets/react-dom.production.min.js", "assets/app.js"]
    try:
        locations = [refs.scripts.index(name) for name in sequence]
    except ValueError as e:
        raise ValueError("React and application script references are incomplete") from e
    if locations != sorted(locations):
        raise ValueError("app.html loads the React scripts in the wrong order")
    return source_files, refs


def build(destination: Path) -> dict[str, object]:
    source_files, refs = preflight()
    dest = destination.expanduser().absolute()
    if dest == ROOT or dest == ASSETS or dest.is_relative_to(ASSETS) or dest == ROOT / "private" or dest == ROOT / "private/frontend":
        raise ValueError("Output must not replace source or production directories")
    if dest.is_relative_to(ROOT) and not dest.is_relative_to(ROOT / "private/frontend"):
        raise ValueError("Output inside source repository must be under private/frontend")
    if ".git" in dest.parts or "node_modules" in dest.parts:
        raise ValueError("Output path cannot be a Git/dependency directory")
    if dest.exists():
        raise FileExistsError(f"Refusing to overwrite existing output: {dest}")
    if dest.parent.exists() and dest.parent.is_symlink():
        raise ValueError("Output parent symlink is not allowed")
    dest.parent.mkdir(parents=True, exist_ok=True)
    with tempfile.TemporaryDirectory(prefix=".ipdams-static-build-", dir=dest.parent) as staging:
        temp = Path(staging)
        for src in [ENTRY, NOTICE, *source_files]:
            rel = src.relative_to(ROOT)
            if src == NOTICE:
                rel = Path("THIRD_PARTY_NOTICES.md")
            target = temp / rel
            target.parent.mkdir(parents=True, exist_ok=True)
            shutil.copyfile(src, target)
        written = sorted(x for x in temp.rglob("*") if x.is_file())
        file_hashes = {p.relative_to(temp).as_posix(): digest(p) for p in written}
        manifest = {
            "format": 1,
            "description": "Byte-identical assembly of audited v0.2.375 legacy frontend assets; no TSX compilation",
            "files": file_hashes,
            "local_html_asset_references": len(refs.paths),
            "react_legacy_versions": {name: version for name, (version, _) in PINNED.items()},
        }
        (temp / "build-manifest.json").write_text(json.dumps(manifest, indent=2, sort_keys=True, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")
        temp.rename(dest)
    return manifest


def main() -> int:
    parser = argparse.ArgumentParser(description="Assemble audited legacy static frontend; NEVER deploy automatically")
    parser.add_argument("--output", type=Path, default=DEFAULT_DEST, help="New, nonexistent output directory")
    args = parser.parse_args()
    try:
        manifest = build(args.output)
    except (ValueError, OSError, UnicodeError) as e:
        parser.exit(2, f"STOP: {e}\n")
    print(f"PASS: assembled {len(manifest['files'])} verified frontend files; {manifest['local_html_asset_references']} HTML references; legacy React pinned")
    print(f"Review-only output: {args.output.expanduser().absolute()}")
    print("No deployment, PHP/SQL operation, npm install or TSX overwrite performed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
