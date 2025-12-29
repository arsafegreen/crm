<?php
// Gera alerta manual para sync_mailboxes.php
require_once __DIR__ . '/../../../htdocs/app/Services/AlertService.php';

$logFile = __DIR__ . '/sync_mailboxes.log';
if (!file_exists($logFile)) {
    echo "Arquivo de log não encontrado: $logFile\n";
    exit(1);
}

$lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
$stats = [
    'accounts' => 0,
    'folders' => 0,
    'fetched' => 0,
    'skipped' => 0,
    'errors' => 0,
];
foreach ($lines as $line) {
    if (preg_match('/Conta #(\d+)/', $line, $m)) {
        $stats['accounts']++;
    }
    if (preg_match('/Pastas sincronizadas: (\d+)/', $line, $m)) {
        $stats['folders'] += (int)$m[1];
    }
    if (preg_match('/Mensagens novas: (\d+)/', $line, $m)) {
        $stats['fetched'] += (int)$m[1];
    }
    if (preg_match('/Mensagens ignoradas: (\d+)/', $line, $m)) {
        $stats['skipped'] += (int)$m[1];
    }
    if (preg_match('/Erros: (\d+)/', $line, $m)) {
        $stats['errors'] += (int)$m[1];
    }
}

\App\Services\AlertService::push(
    'sync.mailboxes',
    'Resumo manual da sincronização de caixas de email',
    $stats
);
echo "Alerta gerado:\n" . json_encode($stats, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
