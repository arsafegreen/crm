<?php

declare(strict_types=1);

$bodyClassValue = isset($bodyClass) ? (string)$bodyClass : 'plain-layout';
$titleSuffix = isset($pageTitle) ? (string)$pageTitle : 'Mensagem';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="<?= htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8'); ?>">
    <title><?= htmlspecialchars(config('app.name') . ' · ' . $titleSuffix, ENT_QUOTES, 'UTF-8'); ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600&display=swap" rel="stylesheet">
    <style>
        :root {
            font-family: 'Space Grotesk', 'Segoe UI', system-ui, sans-serif;
            background: #edf1f7;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            min-height: 100vh;
            background: #edf1f7;
            color: #0f172a;
        }
        body.plain-layout {
            padding: 0;
        }
    </style>
</head>
<body class="<?= htmlspecialchars($bodyClassValue, ENT_QUOTES, 'UTF-8'); ?>">
<?= $content; ?>
</body>
</html>
