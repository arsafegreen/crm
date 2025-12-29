#!/usr/bin/env python3
"""Generate or append to a consolidated daily checkpoint log with git context."""
from __future__ import annotations

import argparse
import subprocess
from datetime import datetime
from pathlib import Path


def locate_repo_root(start: Path) -> Path:
    current = start
    for candidate in [current, *current.parents]:
        if (candidate / ".git").exists():
            return candidate
    return start


def run_git(root: Path, args: list[str]) -> tuple[int, str, str]:
    try:
        process = subprocess.run(
            ["git", "-C", str(root), *args],
            capture_output=True,
            text=True,
            check=False,
        )
    except FileNotFoundError:
        return 127, "", "git executable not found"

    return process.returncode, process.stdout.strip(), process.stderr.strip()


def summarize_status(status_output: str, limit: int = 15) -> list[str]:
    lines = [line for line in status_output.splitlines() if line.strip()]
    if not lines:
        return ["Nenhuma alteração pendente (working tree limpo)."]

    trimmed = lines[:limit]
    if len(lines) > limit:
        trimmed.append(f"... (+{len(lines) - limit} arquivos)")
    return trimmed


def build_entry(
    module: str,
    date_label: str,
    branch: str | None,
    commit: str | None,
    status_lines: list[str],
    existing: str,
    notes: str,
    pending: str,
) -> str:
    commit_short = commit[:8] if commit else "N/D"
    branch_label = branch or "N/D"

    def normalize_block(text: str) -> str:
        return text.strip() if text and text.strip() else "-"

    status_section = "\n".join(f"- {line}" for line in status_lines)
    existing_section = normalize_block(existing)
    notes_section = normalize_block(notes)
    pending_section = normalize_block(pending)
    module_label = module.strip() if module.strip() else "Geral"

    body = f"""\
## {date_label} – {module_label}

**Branch:** {branch_label} | **Commit:** {commit_short}

### Contexto / o que já existe
{existing_section}

### O que foi feito hoje
{notes_section}

### Pendências e próximos passos
{pending_section}

### Working tree
{status_section}
"""
    return body.strip() + "\n"


def main() -> int:
    parser = argparse.ArgumentParser(description="Create or append a daily checkpoint entry")
    parser.add_argument("--module", default="Geral", help="Nome curto do módulo ou foco do dia")
    parser.add_argument("--notes", default="", help="Resumo do que foi feito hoje")
    parser.add_argument("--existing", default="", help="Resumo do que já existe e deve ser lembrado")
    parser.add_argument("--pending", default="", help="Pendências ou próximos passos")
    parser.add_argument("--log-file", default="docs/checkpoints/daily.md", help="Arquivo markdown consolidado para checkpoints")
    parser.add_argument("--date", default=datetime.now().strftime("%Y-%m-%d"), help="Data a registrar (YYYY-MM-DD)")
    args = parser.parse_args()

    repo_root = locate_repo_root(Path(__file__).resolve().parents[1])
    log_path = (repo_root / args.log_file).resolve()
    log_path.parent.mkdir(parents=True, exist_ok=True)

    date_label = args.date

    git_dir = repo_root / ".git"
    if git_dir.exists():
        status_code, branch, _ = run_git(repo_root, ["rev-parse", "--abbrev-ref", "HEAD"])
        branch_value = branch if status_code == 0 else None

        status_code, commit, _ = run_git(repo_root, ["rev-parse", "HEAD"])
        commit_value = commit if status_code == 0 else None

        status_code, status_output, status_err = run_git(repo_root, ["status", "--short"])
        if status_code != 0:
            status_lines = [f"Falha ao obter git status: {status_err or status_output or status_code}"]
        else:
            status_lines = summarize_status(status_output)
    else:
        branch_value = None
        commit_value = None
        status_lines = [f"Repositório git não encontrado em {repo_root}"]

    entry = build_entry(
        module=args.module,
        date_label=date_label,
        branch=branch_value,
        commit=commit_value,
        status_lines=status_lines,
        existing=args.existing,
        notes=args.notes,
        pending=args.pending,
    )

    if not log_path.exists():
        header = "# Daily Checkpoints\n\n"
        log_path.write_text(header + entry + "\n", encoding="utf-8")
    else:
        with log_path.open("a", encoding="utf-8") as handle:
            handle.write("\n" + entry + "\n")

    print(f"Checkpoint atualizado em: {log_path}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
