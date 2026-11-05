#!/usr/bin/env python3
"""Build and verify a deterministic, allowlisted WordPress plugin ZIP."""

from __future__ import annotations

import argparse
import hashlib
import os
from pathlib import Path, PurePosixPath
import re
import stat
import tempfile
import zipfile


ROOT = Path(__file__).resolve().parents[1]
SLUG = ROOT.name
FIXED_TIME = (1980, 1, 1, 0, 0, 0)
DENIED_PARTS = {".git", ".github", "node_modules", "tests", "test", "dist", "scripts", "__pycache__"}
DENIED_FILES = {".env", "release-files.txt", "composer.json", "composer.lock", "package.json", "package-lock.json"}


def header_value(source: str, key: str) -> str | None:
    match = re.search(rf"^[ \t]*(?:\*[ \t]*)?{re.escape(key)}:[ \t]*(.+)$", source, re.I | re.M)
    return match.group(1).strip() if match else None


def release_files() -> tuple[list[Path], str]:
    if not re.fullmatch(r"[a-z0-9]+(?:-[a-z0-9]+)*", SLUG):
        raise ValueError("Repository directory must be the plugin slug")
    manifest = ROOT / "release-files.txt"
    if not manifest.is_file() or manifest.is_symlink():
        raise ValueError("Missing or symlinked release-files.txt")
    selected: set[Path] = set()
    for number, raw in enumerate(manifest.read_text(encoding="utf-8").splitlines(), 1):
        entry = raw.strip()
        if not entry or entry.startswith("#"):
            continue
        if any(char in entry for char in "\\*?["):
            raise ValueError(f"Manifest line {number}: use literal POSIX paths")
        relative = PurePosixPath(entry)
        if relative.is_absolute() or any(part in {"", ".", ".."} for part in entry.split("/")):
            raise ValueError(f"Manifest line {number}: unsafe path")
        source = ROOT.joinpath(*relative.parts)
        if not source.exists() and not source.is_symlink():
            raise ValueError(f"Manifest line {number}: missing {entry}")
        candidates = [source] if source.is_file() or source.is_symlink() else sorted(source.rglob("*"))
        for candidate in candidates:
            rel = candidate.relative_to(ROOT)
            if any(part in DENIED_PARTS for part in rel.parts) or candidate.name in DENIED_FILES or candidate.name.startswith(".env."):
                raise ValueError(f"Development or secret file listed: {rel}")
            if candidate.is_symlink() or any(parent.is_symlink() for parent in candidate.parents if parent != ROOT):
                raise ValueError(f"Symlink listed: {rel}")
            if candidate.is_dir():
                continue
            if not candidate.is_file() or not stat.S_ISREG(candidate.stat().st_mode):
                raise ValueError(f"Non-regular file listed: {rel}")
            selected.add(rel)
    required = {Path(f"{SLUG}.php"), Path("readme.txt"), Path("LICENSE")}
    if not required.issubset(selected):
        raise ValueError("Release manifest lacks main plugin file, readme.txt or LICENSE")
    if len(selected) != len({path.as_posix().casefold() for path in selected}):
        raise ValueError("Case-colliding release paths")
    main = (ROOT / f"{SLUG}.php").read_text(encoding="utf-8")[:8192]
    version = header_value(main, "Version")
    if not header_value(main, "Plugin Name") or not version or not re.fullmatch(r"[0-9]+(?:\.[0-9]+){1,3}(?:[-.][A-Za-z0-9]+)*", version):
        raise ValueError("Missing or invalid plugin name/version header")
    if header_value(main, "Text Domain") != SLUG or header_value(main, "License") != "GPL-2.0-or-later":
        raise ValueError("Text domain or license header mismatch")
    if header_value((ROOT / "readme.txt").read_text(encoding="utf-8"), "Stable tag") != version:
        raise ValueError("readme.txt Stable tag differs from plugin Version")
    if not (ROOT / "LICENSE").read_text(encoding="utf-8").strip():
        raise ValueError("Empty LICENSE")
    return sorted(selected, key=lambda path: path.as_posix()), version


def verify(archive: Path, files: list[Path]) -> None:
    expected = {f"{SLUG}/{path.as_posix()}" for path in files}
    with zipfile.ZipFile(archive) as bundle:
        entries = bundle.infolist()
        names = [entry.filename for entry in entries]
        if len(names) != len(expected) or set(names) != expected or len(names) != len({name.casefold() for name in names}):
            raise ValueError("ZIP contents differ from the release manifest")
        for entry in entries:
            name = entry.filename
            if entry.is_dir() or name.startswith("/") or "\\" in name or any(part in {"", ".", ".."} for part in name.split("/")):
                raise ValueError(f"Unsafe ZIP entry: {name}")
            if stat.S_IFMT(entry.external_attr >> 16) != stat.S_IFREG:
                raise ValueError(f"Non-regular ZIP entry: {name}")
            relative = Path(*PurePosixPath(name).parts[1:])
            if bundle.read(entry) != (ROOT / relative).read_bytes():
                raise ValueError(f"ZIP has stale source bytes: {name}")
        if bundle.testzip():
            raise ValueError("ZIP CRC check failed")


def build() -> Path:
    files, version = release_files()
    output = ROOT / "dist"
    output.mkdir(exist_ok=True)
    archive = output / f"{SLUG}-{version}.zip"
    with tempfile.NamedTemporaryFile(prefix=f".{SLUG}-", suffix=".zip", dir=output, delete=False) as handle:
        temporary = Path(handle.name)
    try:
        with zipfile.ZipFile(temporary, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=9) as bundle:
            for relative in files:
                entry = zipfile.ZipInfo(f"{SLUG}/{relative.as_posix()}", FIXED_TIME)
                entry.create_system = 3
                entry.external_attr = 0o100644 << 16
                entry.compress_type = zipfile.ZIP_DEFLATED
                bundle.writestr(entry, (ROOT / relative).read_bytes(), compress_type=zipfile.ZIP_DEFLATED, compresslevel=9)
        verify(temporary, files)
        os.replace(temporary, archive)
        archive.chmod(0o644)
    finally:
        temporary.unlink(missing_ok=True)
    digest = hashlib.sha256(archive.read_bytes()).hexdigest()
    archive.with_suffix(".zip.sha256").write_text(f"{digest}  {archive.name}\n", encoding="ascii")
    return archive


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--verify", type=Path, help="Verify an existing ZIP against current source")
    args = parser.parse_args()
    try:
        files, _ = release_files()
        archive = args.verify if args.verify else build()
        verify(archive, files)
        print(f"Verified {archive} ({len(files)} files; SHA-256 {hashlib.sha256(archive.read_bytes()).hexdigest()})")
    except (OSError, ValueError, UnicodeError, zipfile.BadZipFile) as exc:
        parser.exit(1, f"release error: {exc}\n")


if __name__ == "__main__":
    main()
