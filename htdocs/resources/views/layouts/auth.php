<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <title><?= htmlspecialchars(config('app.name'), ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600&display=swap" rel="stylesheet">
    <style>
        :root {
            font-family: 'Inter', system-ui, -apple-system, "Segoe UI", sans-serif;
            color-scheme: dark;
            --bg: #0f172a;
            --panel: rgba(15, 23, 42, 0.92);
            --border: rgba(148, 163, 184, 0.28);
            --text: #f8fafc;
            --muted: #94a3b8;
            --accent: #38bdf8;
            --accent-hover: #0ea5e9;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
            background: radial-gradient(circle at 10% 20%, rgba(56, 189, 248, 0.18) 0%, rgba(15, 23, 42, 1) 28%),
                        radial-gradient(circle at 90% 10%, rgba(34, 197, 94, 0.16) 0%, rgba(15, 23, 42, 1) 22%),
                        var(--bg);
            color: var(--text);
        }
        .auth-shell {
            width: 100%;
            max-width: 420px;
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: 18px;
            padding: 32px;
            box-shadow: 0 40px 80px -48px rgba(14, 165, 233, 0.35);
            backdrop-filter: blur(18px);
        }
        .auth-header {
            margin-bottom: 24px;
            text-align: center;
        }
        .auth-header h1 {
            margin: 0 0 8px;
            font-size: 1.5rem;
        }
        .auth-header p {
            margin: 0;
            color: var(--muted);
            font-size: 0.95rem;
        }
        a.auth-link {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            margin-top: 18px;
            font-size: 0.9rem;
            color: var(--accent);
            text-decoration: none;
        }
        a.auth-link:hover {
            color: var(--accent-hover);
        }
    </style>
</head>
<body>
<div class="auth-shell">
    <?= $content ?? ''; ?>
</div>
</body>
</html>
