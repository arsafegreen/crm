<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);

$storageDirs = [
    $basePath . '/storage',
    $basePath . '/storage/cache',
    $basePath . '/storage/logs',
    $basePath . '/storage/uploads',
];

foreach ($storageDirs as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
}

define('ENV_PATH', $basePath . DIRECTORY_SEPARATOR . '.env');

defaultEnv();
ensureEncryptionKey();

$databasePath = $basePath . '/storage/database.sqlite';
if (!file_exists($databasePath)) {
    touch($databasePath);
}

echo "Post-install checks completed." . PHP_EOL;

function defaultEnv(): void
{
    if (!file_exists(ENV_PATH)) {
        $example = ENV_PATH . '.example';
        if (file_exists($example)) {
            copy($example, ENV_PATH);
        }
    }
}

function ensureEncryptionKey(): void
{
    if (!file_exists(ENV_PATH)) {
        return;
    }

    $env = file_get_contents(ENV_PATH);
    if ($env === false) {
        return;
    }

    $needsKey = false;
    if (preg_match('/^DB_ENCRYPTION_KEY=([^\r\n]*)/m', $env, $matches) === 1) {
        $current = trim($matches[1]);
        $needsKey = ($current === '' || $current === 'base64:');
    } else {
        $needsKey = true;
    }

    if (!$needsKey) {
        return;
    }

    $key = 'base64:' . base64_encode(random_bytes(32));

    if (preg_match('/^DB_ENCRYPTION_KEY=.*$/m', $env) === 1) {
        $env = preg_replace('/^DB_ENCRYPTION_KEY=.*$/m', 'DB_ENCRYPTION_KEY=' . $key, $env);
    } else {
        $env = rtrim($env) . PHP_EOL . 'DB_ENCRYPTION_KEY=' . $key . PHP_EOL;
    }

    file_put_contents(ENV_PATH, $env);
}
