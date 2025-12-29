#!/usr/bin/env python3
"""Count lines of code within the project tree."""
from __future__ import annotations

import argparse
import os
from collections import Counter
from pathlib import Path
from typing import Iterable

DEFAULT_EXCLUDES = {".git", ".svn", ".idea", ".vs", "__pycache__", "node_modules"}


def iter_files(root: Path, excludes: set[str]) -> Iterable[Path]:
    for current_root, dirnames, filenames in os.walk(root):
        dirnames[:] = [d for d in dirnames if d not in excludes]
        for filename in filenames:
            if filename in excludes:
                continue
            yield Path(current_root, filename)


def count_lines(path: Path, chunk_size: int = 1024 * 512) -> int:
    size = path.stat().st_size
    if size == 0:
        return 0

    total = 0
    last_byte = b"\n"
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(chunk_size), b""):
            total += chunk.count(b"\n")
            last_byte = chunk[-1:]

    if last_byte not in (b"\n", b"\r"):
        total += 1
    return total


def summarize(root: Path, excludes: set[str]) -> dict[str, object]:
    total_lines = 0
    total_files = 0
    ext_counter: Counter[str] = Counter()
    dir_counter: Counter[str] = Counter()
    skipped: list[tuple[Path, str]] = []

    for file_path in iter_files(root, excludes):
        try:
            lines = count_lines(file_path)
        except Exception as exc:  # noqa: BLE001 - provide context later
            skipped.append((file_path, str(exc)))
            continue

        total_lines += lines
        total_files += 1
        ext = file_path.suffix.lower() or "<no-ext>"
        ext_counter[ext] += lines
        rel = file_path.relative_to(root)
        top = rel.parts[0] if rel.parts else rel.name
        dir_counter[top] += lines

    return {
        "root": root,
        "total_lines": total_lines,
        "total_files": total_files,
        "extension_breakdown": ext_counter,
        "top_dirs": dir_counter,
        "skipped": skipped,
    }


def main() -> int:
    parser = argparse.ArgumentParser(description="Count lines of code within a directory")
    parser.add_argument("--root", default=str(Path(__file__).resolve().parents[1]), help="Directory to scan")
    parser.add_argument("--include-hidden", action="store_true", help="Do not ignore dot-directories")
    parser.add_argument("--extra-exclude", action="append", default=[], help="Additional directory names to skip")
    args = parser.parse_args()

    root = Path(args.root).resolve()
    if not root.is_dir():
        raise SystemExit(f"Root directory not found: {root}")

    excludes = set(args.extra_exclude)
    if not args.include_hidden:
        excludes |= DEFAULT_EXCLUDES

    result = summarize(root, excludes)

    print(f"Root...............: {result['root']}")
    print(f"Total de arquivos..: {result['total_files']:,}")
    print(f"Total de linhas....: {result['total_lines']:,}")

    top_dirs = result["top_dirs"].most_common(5)
    if top_dirs:
        print("\nTop diretórios por linhas:")
        for name, lines in top_dirs:
            print(f"  - {name:<20} {lines:,} linhas")

    top_ext = result["extension_breakdown"].most_common(5)
    if top_ext:
        print("\nTop extensões por linhas:")
        for ext, lines in top_ext:
            print(f"  - {ext:<10} {lines:,} linhas")

    if result["skipped"]:
        print(f"\nArquivos ignorados: {len(result['skipped'])}")

    return 0


if __name__ == "__main__":
    raise SystemExit(main())
