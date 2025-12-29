<?php
// Executa a rotina de manutenção diária via PowerShell.
// Retorna JSON.

header('Content-Type: application/json');

$nowHour = (int)date('G');
$withinWindow = ($nowHour >= 22 || $nowHour < 5);
$manual = !$withinWindow;

$root = realpath(__DIR__ . DIRECTORY_SEPARATOR . '..');
$script = $root . DIRECTORY_SEPARATOR . 'scripts' . DIRECTORY_SEPARATOR . 'daily_whatsapp_maintenance.ps1';

if (!is_file($script)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'script_not_found']);
    exit;
}

$cmd = 'powershell -ExecutionPolicy Bypass -File ' . escapeshellarg($script) . ' -Root ' . escapeshellarg($root);
$descriptor = [1 => ['pipe','w'], 2 => ['pipe','w']];
$proc = proc_open($cmd, $descriptor, $pipes);
if (!is_resource($proc)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'proc_open_failed']);
    exit;
}
$output = stream_get_contents($pipes[1]);
$error = stream_get_contents($pipes[2]);
foreach ($pipes as $p) { fclose($p); }
$status = proc_close($proc);

if ($status !== 0) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'script_failed', 'detail' => trim($error)]);
    exit;
}

echo json_encode([
    'ok' => true,
    'manual' => $manual,
    'ran_at' => date('c'),
    'output' => trim($output),
]);
