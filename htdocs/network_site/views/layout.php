<?php
// Basic layout for standalone network site (futuristic theme)
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Network – Vitrine</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            font-family: 'Space Grotesk', system-ui, sans-serif;
            color-scheme: dark;
            --bg: #050710;
            --panel: rgba(7, 12, 25, 0.9);
            --panel-alt: rgba(16, 21, 38, 0.65);
            --border: rgba(148, 163, 184, 0.22);
            --text: #e9eef6;
            --muted: #9fb1c8;
            --accent: #22d3ee;
            --accent-strong: #a855f7;
            --glow: rgba(34, 211, 238, 0.28);
            --grid: rgba(255, 255, 255, 0.04);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at 12% 18%, rgba(34, 211, 238, 0.2) 0%, rgba(5, 7, 16, 1) 32%),
                radial-gradient(circle at 88% 12%, rgba(168, 85, 247, 0.24) 0%, rgba(5, 7, 16, 1) 28%),
                linear-gradient(145deg, #050914 0%, #050710 60%, #03060d 100%);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px 18px;
        }
        .shell {
            width: 100%;
            max-width: 980px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 26px;
            padding: 36px 38px;
            box-shadow: 0 40px 120px -48px rgba(34, 211, 238, 0.35), 0 18px 48px -32px rgba(168, 85, 247, 0.35);
            position: relative;
            overflow: hidden;
        }
        .shell::after {
            content: '';
            position: absolute;
            inset: 0;
            background:
                radial-gradient(circle at 60% 20%, var(--glow) 0%, transparent 32%),
                linear-gradient(135deg, rgba(255,255,255,0.04) 0%, rgba(255,255,255,0) 40%);
            pointer-events: none;
        }
        .shell::before {
            content: '';
            position: absolute;
            inset: -1px;
            background-image: radial-gradient(circle at 20px 20px, var(--grid) 2px, transparent 0), radial-gradient(circle at 60px 60px, var(--grid) 2px, transparent 0);
            background-size: 120px 120px;
            opacity: 0.35;
            pointer-events: none;
            mask-image: radial-gradient(circle at 50% 50%, rgba(255,255,255,0.65), transparent 70%);
        }
        .grid { position: relative; display: grid; gap: 26px; }
        .panel {
            background: var(--panel-alt);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 20px;
            display: grid;
            gap: 12px;
            box-shadow: inset 0 1px 0 rgba(255,255,255,0.04);
        }
        .highlight { border: 1px solid rgba(34, 211, 238, 0.32); background: linear-gradient(135deg, rgba(34, 211, 238, 0.12), rgba(168, 85, 247, 0.12)); }
        .eyebrow { display: inline-flex; gap: 10px; padding: 8px 14px; border-radius: 999px; background: rgba(34, 211, 238, 0.12); border: 1px solid rgba(34, 211, 238, 0.45); color: #cffafe; font-size: 0.85rem; width: fit-content; }
        h1 { margin: 0; font-size: clamp(1.9rem, 2.4vw, 2.4rem); letter-spacing: -0.03em; }
        .lede { margin: 0; color: var(--muted); line-height: 1.6; max-width: 48ch; }
        .cta-row { display: flex; flex-wrap: wrap; gap: 12px; margin-top: 6px; }
        .cta { display: inline-flex; align-items: center; justify-content: center; gap: 8px; padding: 12px 16px; border-radius: 999px; border: 1px solid rgba(34, 211, 238, 0.6); background: linear-gradient(120deg, var(--accent), var(--accent-strong)); color: #050710; text-decoration: none; font-weight: 600; box-shadow: 0 10px 30px -16px rgba(34, 211, 238, 0.8); transition: transform 160ms ease, box-shadow 160ms ease; }
        .cta:hover { transform: translateY(-1px); box-shadow: 0 14px 40px -18px rgba(34, 211, 238, 0.9); }
        .cta.alt { background: transparent; color: #67e8f9; border-color: rgba(34, 211, 238, 0.35); box-shadow: none; padding: 10px 14px; font-size: 0.95rem; }
        .pill { display: inline-flex; align-items: center; gap: 8px; padding: 8px 12px; border-radius: 999px; background: rgba(255, 255, 255, 0.06); color: var(--muted); font-size: 0.85rem; }
        .steps { display: grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap: 14px; }
        .step { padding: 12px 12px 10px; border-radius: 14px; border: 1px solid rgba(148, 163, 184, 0.18); background: rgba(255,255,255,0.02); }
        .step p { margin: 6px 0 0; color: var(--muted); line-height: 1.5; }
        .badge-dot { width: 8px; height: 8px; border-radius: 50%; background: var(--accent-strong); }
        .feedback { border: 1px solid rgba(148, 163, 184, 0.4); padding: 12px; border-radius: 12px; }
        .feedback-success { background: rgba(34, 197, 94, 0.12); border-color: rgba(34, 197, 94, 0.45); color: #c6f6d5; }
        .feedback-error { background: rgba(248, 113, 113, 0.12); border-color: rgba(248, 113, 113, 0.45); color: #fecdd3; }
        .form-grid { display: grid; gap: 12px; }
        label { display: grid; gap: 6px; color: var(--muted); font-size: 0.95rem; }
        input, textarea, select { padding: 12px 12px; border-radius: 12px; border: 1px solid rgba(148, 163, 184, 0.24); background: rgba(10, 14, 26, 0.7); color: var(--text); font-size: 1rem; transition: border-color 140ms ease, box-shadow 140ms ease; }
        input:focus, textarea:focus, select:focus { outline: none; border-color: rgba(34, 211, 238, 0.6); box-shadow: 0 0 0 3px rgba(34, 211, 238, 0.18); }
        textarea { resize: vertical; }
        @media (max-width: 720px) { .shell { padding: 28px 24px; border-radius: 20px; } }
    </style>
</head>
<body>
<div class="shell">
    <div class="grid">
        <?= $content ?? '' ?>
    </div>
</div>
</body>
</html>
