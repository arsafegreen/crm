<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <title><?= htmlspecialchars(config('app.name') . ' – Preferências', ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif;
            color-scheme: dark;
            --bg: #020617;
            --panel: rgba(15, 23, 42, 0.92);
            --panel-alt: rgba(15, 23, 42, 0.75);
            --border: rgba(148, 163, 184, 0.28);
            --text: #f8fafc;
            --muted: #94a3b8;
            --accent: #38bdf8;
            --accent-hover: #0ea5e9;
            --success: #22c55e;
            --warning: #fde047;
            --danger: #f87171;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background:
                radial-gradient(circle at 5% 20%, rgba(56, 189, 248, 0.25) 0%, rgba(2, 6, 23, 0.95) 32%),
                radial-gradient(circle at 85% 0%, rgba(34, 197, 94, 0.18) 0%, rgba(2, 6, 23, 0.95) 25%),
                var(--bg);
            color: var(--text);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
        }
        .public-shell {
            width: 100%;
            max-width: 640px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 24px;
            padding: 40px 36px;
            box-shadow: 0 40px 90px -45px rgba(15, 23, 42, 0.85);
            backdrop-filter: blur(16px);
        }
        .public-header {
            display: flex;
            flex-direction: column;
            gap: 8px;
            margin-bottom: 28px;
        }
        .public-header span {
            font-size: 0.85rem;
            text-transform: uppercase;
            letter-spacing: 0.18em;
            color: var(--muted);
        }
        .public-header h1 {
            margin: 0;
            font-size: 1.75rem;
        }
        .public-content {
            display: flex;
            flex-direction: column;
            gap: 18px;
        }
        a.brand-link {
            color: var(--accent);
            text-decoration: none;
            font-size: 0.9rem;
        }
        a.brand-link:hover {
            color: var(--accent-hover);
        }
        @media (max-width: 640px) {
            .public-shell {
                padding: 28px 20px;
                border-radius: 18px;
            }
        }
    </style>
</head>
<body>
<div class="public-shell">
    <div class="public-header">
        <span>Marketing Suite</span>
        <h1>Centro de preferências</h1>
        <a class="brand-link" href="<?= htmlspecialchars(config('app.url', url()), ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noopener">Visitar site principal</a>
    </div>
    <div class="public-content">
        <?= $content ?? ''; ?>
    </div>
</div>
</body>
</html>
