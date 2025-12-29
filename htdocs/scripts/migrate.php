<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';
require __DIR__ . '/../app/Support/helpers.php';

require __DIR__ . '/../bootstrap/app.php';

$connectionName = $argv[1] ?? null;
$migrationPath = $argv[2] ?? null;

if ($migrationPath !== null) {
	$resolved = realpath($migrationPath);
	if ($resolved === false || !is_dir($resolved)) {
		fwrite(STDERR, "Migration path not found: {$migrationPath}" . PHP_EOL);
		exit(1);
	}
	$migrationPath = $resolved;
}

$migrator = new \App\Database\Migrator(null, $migrationPath, $connectionName);
$migrator->run();

echo "Migrations executed successfully." . PHP_EOL;
