<?php
$scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'])), '/');
$baseUrl = $scheme . '://' . $host . ($scriptDir === '' || $scriptDir === '/' ? '' : $scriptDir);
$canonicalUrl = rtrim($baseUrl, '/') . '/chat.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Central SafeGreen ID para atendimento imediato via chat certificado.">
    <link rel="canonical" href="<?= htmlspecialchars($canonicalUrl, ENT_QUOTES, 'UTF-8'); ?>">
    <title>SafeGreen Chat Seguro</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" integrity="sha512-1ycn6IcaQQ40/MKBW2W4Rhis/DbILU74C1vSrLJxCq57Yx04ecqC5Lnw2vK0O79btp6G7iXgP0hKtu2w3Xh1g==" crossorigin="anonymous" referrerpolicy="no-referrer" />
    <link rel="stylesheet" href="/assets/chat-widget.css?v=1">
    <style>
        :root {
            --bg: #010b1f;
            --accent: #00e0ff;
        }

        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            font-family: 'Space Grotesk', system-ui, -apple-system, 'Segoe UI', sans-serif;
            background: radial-gradient(circle at top, rgba(0, 224, 255, 0.2), transparent 60%), #010b1f;
            color: #f5fbff;
            display: flex;
            flex-direction: column;
        }

        header {
            padding: 24px clamp(16px, 6vw, 72px);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
        }

        .logo {
            display: flex;
            align-items: center;
            gap: 12px;
            font-weight: 600;
            letter-spacing: 0.05em;
        }

        .logo span {
            width: 42px;
            height: 42px;
            border-radius: 12px;
            display: grid;
            place-items: center;
            background: linear-gradient(135deg, #00e0ff, #9b3cff);
            color: #02060f;
            font-weight: 700;
        }

        main {
            flex: 1;
            display: grid;
            place-items: center;
            text-align: center;
            padding: 24px;
        }

        .panel {
            max-width: 560px;
            width: 100%;
            background: rgba(4, 16, 38, 0.72);
            border: 1px solid rgba(255, 255, 255, 0.08);
            border-radius: 24px;
            padding: clamp(24px, 5vw, 48px);
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.45);
        }

        .panel h1 { margin-top: 0; margin-bottom: 12px; }
        .panel p { margin: 0 auto 20px; color: #bcd2f5; max-width: 420px; }

        .share-hint {
            margin-top: 24px;
            font-size: 0.9rem;
            color: #8da5d7;
        }

        .floating-chat.is-standalone {
            position: static;
            max-width: 560px;
            width: 100%;
            margin: 28px auto 0;
        }

        footer {
            padding: 18px;
            font-size: 0.85rem;
            text-align: center;
            color: #7f96c4;
        }
    </style>
</head>
<body data-chat-auto-open="true">
    <header>
        <div class="logo">
            <span>SG</span>
            <div>
                SafeGreen · Certificado Digital<br>
                <small>Canal de atendimento prioritário</small>
            </div>
        </div>
        <a href="tel:+5511971380207" style="color: var(--accent); font-weight: 600;">
            <i class="fa-solid fa-phone"></i>
            (11) 97138-0207
        </a>
    </header>

    <main>
        <div class="panel">
            <h1>Chat seguro para clientes e parceiros</h1>
            <?php
            $chatWidgetStandalone = true;
            $chatWidgetAutoOpen = true;
            $chatWidgetStorageKey = 'seloid-floating-lead-share';
            include __DIR__ . '/partials/floating-chat-widget.php';
            ?>
        </div>
    </main>

    <footer>
        SafeGreen ID · suporte@selodigitalonline.com.br · WhatsApp (11) 97138-0207
    </footer>

    <script src="/assets/chat-widget.js?v=1" defer></script>
</body>
</html>
