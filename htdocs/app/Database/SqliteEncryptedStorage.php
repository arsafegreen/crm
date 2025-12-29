<?php

declare(strict_types=1);

namespace App\Database;

use App\Support\Encryption;
use RuntimeException;

final class SqliteEncryptedStorage
{
    private const STALE_COUNTER_TTL = 600; // seconds
    private string $plainPath;
    private string $encryptedPath;
    private string $lockFile;
    private string $counterFile;
    private string $key;
    private bool $shutdownRegistered = false;

    public function __construct(string $plainPath, string $encryptedPath, string $key)
    {
        $this->plainPath = $this->normalizePath($plainPath);
        $this->encryptedPath = $this->normalizePath($encryptedPath);
        $this->lockFile = $this->plainPath . '.lock';
        $this->counterFile = $this->plainPath . '.ref';
        $this->key = $key;
    }

    public function prepare(): void
    {
        $this->withLock(function (): void {
            $this->ensureDirectory(dirname($this->plainPath));
            $this->ensureDirectory(dirname($this->encryptedPath));

            $this->recoverStalePlainCopy();

            $plainExists = file_exists($this->plainPath);
            $encryptedExists = file_exists($this->encryptedPath);
            $plainValid = $plainExists && $this->isPlainDatabaseValid();

            if ($encryptedExists && (!$plainExists || !$plainValid)) {
                $this->decryptToPlain();
                $plainExists = true;
                $plainValid = true;
            }

            if (!$plainExists) {
                touch($this->plainPath);
                $plainExists = true;
                $plainValid = false;
            }

            if (!$encryptedExists && $plainExists) {
                $this->encryptFromPlain();
            }

            $this->writeCounter($this->readCounter() + 1);
        });
    }

    public function registerShutdown(?callable $beforeSecure = null): void
    {
        if ($this->shutdownRegistered) {
            return;
        }

        $this->shutdownRegistered = true;

        register_shutdown_function(function () use ($beforeSecure): void {
            if ($beforeSecure !== null) {
                $beforeSecure();
            }

            $this->secure();
        });
    }

    public function secure(): void
    {
        $this->withLock(function (): void {
            $count = max(0, $this->readCounter() - 1);

            if ($count === 0) {
                $this->encryptFromPlain();
                $this->erasePlainCopy();
            }

            $this->writeCounter($count);
        });
    }

    private function encryptFromPlain(): void
    {
        if (!file_exists($this->plainPath)) {
            return;
        }

        $this->ensureMemoryForFile($this->plainPath);
        $data = $this->readFileContents($this->plainPath, 'Não foi possível ler o arquivo SQLite para criptografia.');

        $payload = Encryption::encrypt($data, $this->key);
        file_put_contents($this->encryptedPath, $payload, LOCK_EX);
    }

    private function decryptToPlain(): void
    {
        $this->ensureMemoryForFile($this->encryptedPath);
        $payload = $this->readFileContents($this->encryptedPath, 'Falha ao abrir o arquivo SQLite criptografado.');
        if ($payload === '') {
            throw new RuntimeException('Arquivo SQLite criptografado está vazio ou inacessível.');
        }

        $data = Encryption::decrypt($payload, $this->key);
        file_put_contents($this->plainPath, $data, LOCK_EX);
    }

    private function withLock(callable $callback): void
    {
        $handle = fopen($this->lockFile, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Não foi possível abrir o arquivo de bloqueio para o SQLite.');
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                throw new RuntimeException('Falha ao obter lock exclusivo para gerenciar o SQLite criptografado.');
            }

            $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function readCounter(): int
    {
        if (!file_exists($this->counterFile)) {
            return 0;
        }

        $value = trim((string) file_get_contents($this->counterFile));
        return $value === '' ? 0 : (int) $value;
    }

    private function writeCounter(int $count): void
    {
        file_put_contents($this->counterFile, (string) max(0, $count), LOCK_EX);
    }

    private function ensureDirectory(string $path): void
    {
        if ($path === '' || is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException(sprintf('Não foi possível criar o diretório %s.', $path));
        }
    }

    private function erasePlainCopy(): void
    {
        if (!file_exists($this->plainPath)) {
            return;
        }

        $attempts = 0;
        while ($attempts < 5) {
            if (@unlink($this->plainPath)) {
                return;
            }

            usleep(100_000);
            clearstatcache(true, $this->plainPath);
            $attempts++;
        }

        file_put_contents($this->plainPath, '', LOCK_EX);
        if (function_exists('chmod')) {
            @chmod($this->plainPath, 0600);
        }
    }

    private function isPlainDatabaseValid(): bool
    {
        if (!file_exists($this->plainPath)) {
            return false;
        }

        $size = filesize($this->plainPath);
        if ($size === false || $size < 16) {
            return false;
        }

        $handle = fopen($this->plainPath, 'rb');
        if ($handle === false) {
            return false;
        }

        $header = fread($handle, 16) ?: '';
        fclose($handle);

        return strncmp($header, 'SQLite format 3', 15) === 0;
    }

    private function readFileContents(string $path, string $errorMessage, int $chunkSize = 8_388_608): string
    {
        $handle = fopen($path, 'rb');
        if ($handle === false) {
            throw new RuntimeException($errorMessage);
        }

        try {
            $buffer = '';
            while (!feof($handle)) {
                $chunk = fread($handle, $chunkSize);
                if ($chunk === false) {
                    throw new RuntimeException($errorMessage);
                }

                $buffer .= $chunk;

                if (PHP_INT_SIZE === 4 && strlen($buffer) > 1_800_000_000) {
                    throw new RuntimeException('O arquivo do banco criptografado excede o limite suportado por builds 32-bit do PHP. Utilize PHP 64-bit ou compacte o banco.');
                }
            }

            return $buffer;
        } finally {
            fclose($handle);
        }
    }

    private function ensureMemoryForFile(string $path): void
    {
        if (!function_exists('ini_get') || !function_exists('ini_set')) {
            return;
        }

        $currentLimit = ini_get('memory_limit');
        if ($currentLimit === false || $currentLimit === '' || $currentLimit === '-1') {
            return;
        }

        $currentBytes = $this->parseMemoryLimit($currentLimit);
        if ($currentBytes === null) {
            return;
        }

        $size = filesize($path);
        if ($size === false) {
            return;
        }

        $targetBytes = (int)ceil($size * 1.2) + 64 * 1024 * 1024;
        if ($targetBytes <= $currentBytes) {
            return;
        }

        $targetMegabytes = max(512, (int)ceil($targetBytes / 1_048_576));
        @ini_set('memory_limit', $targetMegabytes . 'M');
    }

    private function parseMemoryLimit(string $value): ?int
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if ($trimmed === '-1') {
            return null;
        }

        $unit = strtolower(substr($trimmed, -1));
        $number = (float)$trimmed;

        return match ($unit) {
            'g' => (int)($number * 1_073_741_824),
            'm' => (int)($number * 1_048_576),
            'k' => (int)($number * 1024),
            default => (int)$number,
        };
    }

    private function normalizePath(string $path): string
    {
        if ($path === '') {
            throw new RuntimeException('O caminho do banco de dados não pode ser vazio.');
        }

        if ($path[0] === '/' || $path[0] === '\\' || preg_match('/^[A-Z]:/i', $path) === 1) {
            return $path;
        }

        return base_path($path);
    }

    private function recoverStalePlainCopy(): void
    {
        $count = $this->readCounter();
        if ($count === 0) {
            return;
        }

        $age = $this->counterAgeSeconds();
        if ($age !== null && $age < self::STALE_COUNTER_TTL) {
            return;
        }

        if (file_exists($this->plainPath)) {
            $this->encryptFromPlain();
            $this->erasePlainCopy();
        }

        $this->writeCounter(0);
    }

    private function counterAgeSeconds(): ?int
    {
        if (!file_exists($this->counterFile)) {
            return null;
        }

        $mtime = filemtime($this->counterFile);
        if ($mtime === false) {
            return null;
        }

        return max(0, time() - $mtime);
    }
}
