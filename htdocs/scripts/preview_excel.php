<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$path = $argv[1] ?? null;

if (!defined('STDERR')) {
    define('STDERR', fopen('php://stderr', 'wb'));
}

if ($path === null) {
    fwrite(STDERR, "Informe o caminho para o arquivo Excel.\n");
    exit(1);
}

if (!preg_match('/^[A-Z]:/i', $path) && !str_starts_with($path, '/') && !str_starts_with($path, '\\')) {
    $path = realpath(__DIR__ . '/../' . ltrim($path, '/\\')) ?: $path;
}

if (!file_exists($path)) {
    fwrite(STDERR, "Arquivo não encontrado: {$path}\n");
    exit(1);
}

$spreadsheet = IOFactory::load($path);
$sheet = $spreadsheet->getActiveSheet();

$rows = $sheet->toArray(null, true, true, true);

$headers = [];
$data = [];

foreach ($rows as $index => $row) {
    if ($index === 1) {
        $headers = array_map(static fn($value) => $value !== null ? trim((string) $value) : '', $row);
        continue;
    }

    $assoc = [];
    foreach ($row as $column => $value) {
        $key = $headers[$column] ?? $column;
        $assoc[$key] = $value;
    }

    $data[] = $assoc;
}

$preview = array_slice($data, 0, 10);

echo json_encode([
    'headers' => $headers,
    'preview' => $preview,
    'total_rows' => count($data),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
