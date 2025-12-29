<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$relativePath = $argv[1] ?? null;
if ($relativePath === null) {
    fwrite(STDERR, "Usage: php inspect_upload.php <relative-path>\n");
    exit(1);
}

$path = realpath($relativePath);
if ($path === false) {
    fwrite(STDERR, "File not found: {$relativePath}\n");
    exit(1);
}

$spreadsheet = IOFactory::load($path);
$sheet = $spreadsheet->getActiveSheet();
$rows = $sheet->toArray(null, true, true, true);

if ($rows === []) {
    fwrite(STDERR, "Empty sheet\n");
    exit(1);
}

$headers = $rows[1] ?? [];

$previewRows = array_slice($rows, 1, 5);

echo "Headers:\n";
foreach ($headers as $col => $value) {
    echo sprintf("  %s => %s\n", $col, (string)$value);
}

echo "\nSample rows:\n";
foreach ($previewRows as $index => $row) {
    $line = [];
    foreach ($headers as $col => $headerValue) {
        $line[] = sprintf("%s: %s", (string)$headerValue, (string)($row[$col] ?? ''));
    }
    echo '- ' . implode(' | ', $line) . "\n";
}
