<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

use function base_path;

final class Connection
{
    /** @var array<string, PDO> */
    private static array $pdoPool = [];

    public static function instance(?string $name = null): PDO
    {
        $config = config('database');
        $defaultConnection = $config['default'] ?? 'sqlite';
        $connectionName = $name ?? $defaultConnection;

        if (isset(self::$pdoPool[$connectionName]) && self::$pdoPool[$connectionName] instanceof PDO) {
            return self::$pdoPool[$connectionName];
        }

        $connection = $config['connections'][$connectionName] ?? null;

        if ($connection === null) {
            throw new RuntimeException(sprintf('Configuração de banco de dados "%s" não encontrada.', $connectionName));
        }

        $driver = $connection['driver'] ?? 'sqlite';

        if ($driver === 'sqlite') {
            $path = self::normalizePath($connection['database'] ?? storage_path('database.sqlite'));
            $encryption = $connection['encryption'] ?? [];

            $encryptionEnabled = filter_var($encryption['enabled'] ?? false, FILTER_VALIDATE_BOOL) ?? false;
            $storage = null;

            if ($encryptionEnabled) {
                $key = self::resolveEncryptionKey($encryption);
                if ($key === '') {
                    throw new RuntimeException('DB_ENCRYPTION_KEY deve ser configurada quando a criptografia do SQLite estiver habilitada.');
                }

                $encryptedPath = self::normalizePath((string)($encryption['file'] ?? ($path . '.enc')));
                $storage = new SqliteEncryptedStorage($path, $encryptedPath, $key);
                $storage->prepare();
            } else {
                if (!file_exists($path)) {
                    self::ensureDirectory(dirname($path));
                    touch($path);
                }

                // Clear read-only flag so SQLite can write WAL/locks.
                if (!is_writable($path)) {
                    @chmod($path, 0666);
                }
            }

            $dsn = 'sqlite:' . $path;
            $pdo = new PDO($dsn);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA temp_store = MEMORY');
            $pdo->exec('PRAGMA cache_size = -20000');
            $pdo->exec('PRAGMA busy_timeout = 5000');
            $pdo->exec('PRAGMA foreign_keys = ON');
            self::$pdoPool[$connectionName] = $pdo;

            if ($storage !== null) {
                $storage->registerShutdown(function () use ($connectionName): void {
                    self::disconnect($connectionName);
                });
            }

            return self::$pdoPool[$connectionName];
        }

        throw new RuntimeException(sprintf('Driver %s não suportado no momento.', $driver));
    }

    public static function disconnect(?string $name = null): void
    {
        if ($name === null) {
            self::$pdoPool = [];
            return;
        }

        unset(self::$pdoPool[$name]);
    }

    private static function normalizePath(string $path): string
    {
        if ($path === '') {
            throw new RuntimeException('O caminho do banco de dados não pode ser vazio.');
        }

        if (preg_match('/^(?:[A-Z]:|\\\\|\/)/i', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private static function ensureDirectory(string $path): void
    {
        if ($path === '' || is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Não foi possível criar o diretório %s.', $path));
        }
    }

    private static function resolveEncryptionKey(array $encryption): string
    {
        $rawKey = trim((string)($encryption['key'] ?? ''));
        if ($rawKey !== '' && $rawKey !== 'base64:') {
            return $rawKey;
        }

        $keyFile = trim((string)($encryption['key_file'] ?? ''));
        if ($keyFile === '') {
            return '';
        }

        $keyFilePath = self::normalizePath($keyFile);
        if (!is_file($keyFilePath)) {
            throw new RuntimeException(sprintf('Arquivo de chave não encontrado em %s.', $keyFilePath));
        }

        $contents = trim((string) file_get_contents($keyFilePath));
        if ($contents === '' || $contents === 'base64:') {
            throw new RuntimeException(sprintf('Arquivo de chave %s está vazio ou inválido.', $keyFilePath));
        }

        return $contents;
    }
}
