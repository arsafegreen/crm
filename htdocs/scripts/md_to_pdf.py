#!/usr/bin/env python3
"""Utility to convert a Markdown file into a simple PDF using fpdf."""
from __future__ import annotations

import sys
from pathlib import Path
from typing import Iterable

from fpdf import FPDF


class MarkdownPDF:
    """Very small helper that renders a subset of Markdown into PDF text."""

    def __init__(self, title: str) -> None:
        self.pdf = FPDF()
        self.pdf.set_auto_page_break(auto=True, margin=15)
        self.pdf.add_page()
        self.pdf.set_title(title)
        self.pdf.set_font("Helvetica", size=11)

    @staticmethod
    def _clean(text: str) -> str:
        replacements = {
            "→": "->",
            "←": "<-",
            "↔": "<->",
            "—": "-",
            "–": "-",
            "•": "-",
            "…": "...",
            "“": '"',
            "”": '"',
            "’": "'",
        }
        for src, dst in replacements.items():
            text = text.replace(src, dst)
        return text

    def _write_heading(self, text: str, level: int) -> None:
        sizes = {1: 18, 2: 16, 3: 14, 4: 13}
        size = sizes.get(level, 12)
        self.pdf.set_font("Helvetica", style="B", size=size)
        self.pdf.multi_cell(0, 8, self._clean(text))
        self.pdf.ln(2)
        self.pdf.set_font("Helvetica", size=11)

    def _write_paragraph(self, text: str) -> None:
        self.pdf.multi_cell(0, 6, self._clean(text))

    def _write_bullet(self, text: str, indent: float = 4.0, bullet: str = "-" ) -> None:
        x_before = self.pdf.get_x()
        self.pdf.set_x(self.pdf.l_margin + indent)
        cleaned = self._clean(text)
        prefix = f"{bullet} " if bullet else ""
        self.pdf.multi_cell(0, 6, f"{prefix}{cleaned}")
        self.pdf.set_x(x_before)

    def render(self, lines: Iterable[str]) -> None:
        in_code_block = False

        for raw_line in lines:
            line = raw_line.rstrip("\n")
            stripped = line.strip()

            if not stripped:
                self.pdf.ln(4)
                continue

            if stripped.startswith("#"):
                hashes = len(stripped) - len(stripped.lstrip("#"))
                heading_text = stripped[hashes:].strip()
                self._write_heading(heading_text, hashes)
                continue

            if stripped.startswith(('- ', '* ')):
                self._write_bullet(stripped[2:].strip())
                continue

            if stripped[0].isdigit() and stripped.split('.', 1)[0].isdigit():
                # Ordered list entry like "1. Step".
                number, _, rest = stripped.partition('.')
                bullet_text = rest.strip()
                self._write_bullet(f"{number}. {bullet_text}", bullet="")
                continue

            if stripped.startswith('|') and stripped.endswith('|'):
                # Render table rows as monospaced text to preserve layout.
                self.pdf.set_font("Courier", size=9)
                self.pdf.multi_cell(0, 5, self._clean(stripped))
                self.pdf.set_font("Helvetica", size=11)
                continue

            if stripped.startswith('```'):
                in_code_block = not in_code_block
                if in_code_block:
                    self.pdf.set_font("Courier", size=9)
                else:
                    self.pdf.set_font("Helvetica", size=11)
                continue

            if in_code_block:
                self.pdf.multi_cell(0, 5, self._clean(line))
                continue

            # Default paragraph text.
            self._write_paragraph(line)

        # Reset font after potential code block.
        self.pdf.set_font("Helvetica", size=11)

    def output(self, destination: Path) -> None:
        destination.parent.mkdir(parents=True, exist_ok=True)
        self.pdf.output(str(destination))


def markdown_to_pdf(source: Path, target: Path) -> None:
    pdf = MarkdownPDF(title=source.stem)
    pdf.render(source.read_text(encoding="utf-8").splitlines())
    pdf.output(target)


def main(argv: list[str]) -> int:
    if len(argv) != 3:
        print("Usage: python md_to_pdf.py <input.md> <output.pdf>", file=sys.stderr)
        return 1

    source = Path(argv[1]).resolve()
    target = Path(argv[2]).resolve()

    if not source.is_file():
        print(f"Input file not found: {source}", file=sys.stderr)
        return 2

    markdown_to_pdf(source, target)
    print(f"PDF gerado em: {target}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main(sys.argv))
