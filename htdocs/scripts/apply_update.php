<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado via CLI.\n");
    exit(1);
}

if ($argc < 2) {
    fwrite(STDERR, "Uso: php scripts/apply_update.php <caminho-pacote.zip>\n");
    exit(1);
}

$packagePath = $argv[1];
if (!is_file($packagePath)) {
    fwrite(STDERR, "Pacote não encontrado em {$packagePath}.\n");
    exit(1);
}

$basePath = realpath(__DIR__ . '/..');
if ($basePath === false) {
    fwrite(STDERR, "Não foi possível resolver o caminho base do projeto.\n");
    exit(1);
}

$storagePath = $basePath . DIRECTORY_SEPARATOR . 'storage';
$releasesPath = $storagePath . DIRECTORY_SEPARATOR . 'releases';
if (!is_dir($releasesPath) && !mkdir($releasesPath, 0775, true) && !is_dir($releasesPath)) {
    fwrite(STDERR, "Falha ao preparar diretório de releases em {$releasesPath}.\n");
    exit(1);
}

$tmpPath = $releasesPath . DIRECTORY_SEPARATOR . 'apply_' . date('Ymd_His');
if (!mkdir($tmpPath, 0775, true) && !is_dir($tmpPath)) {
    fwrite(STDERR, "Não foi possível criar diretório temporário em {$tmpPath}.\n");
    exit(1);
}

$cleanup = function () use (&$tmpPath): void {
    if ($tmpPath !== null && is_dir($tmpPath)) {
        recursiveDelete($tmpPath);
    }
};

try {
    extractPackage($packagePath, $tmpPath);

    $manifestPath = $tmpPath . DIRECTORY_SEPARATOR . 'release_manifest.json';
    if (!is_file($manifestPath)) {
        throw new RuntimeException('Manifesto da release não encontrado.');
    }

    $manifestData = json_decode((string)file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
    $files = $manifestData['files'] ?? [];
    if (!is_array($files) || $files === []) {
        throw new RuntimeException('Manifesto não contém lista de arquivos.');
    }

    validateFileList($files);

    $backupPath = createBackupZip($basePath, $files);
    echo "Backup criado em {$backupPath}\n";

    $selfRelative = normalizeRelative(substr(__FILE__, strlen($basePath) + 1));
    $pendingSelfUpdate = null;

    foreach ($files as $relative) {
        $source = $tmpPath . DIRECTORY_SEPARATOR . convertToFilesystemPath($relative);
        if (is_dir($source)) {
            continue;
        }

        if (!is_file($source)) {
            fwrite(STDOUT, "Aviso: arquivo {$relative} não encontrado no pacote. Ignorando.\n");
            continue;
        }

        $destination = $basePath . DIRECTORY_SEPARATOR . convertToFilesystemPath($relative);
        ensureDirectory(dirname($destination));

        if ($relative === $selfRelative) {
            $pendingSelfUpdate = [$source, $destination];
            continue;
        }

        if (!copy($source, $destination)) {
            throw new RuntimeException("Falha ao copiar {$relative}.");
        }
    }

    if ($pendingSelfUpdate !== null) {
        register_shutdown_function(static function (array $paths): void {
            [$source, $destination] = $paths;
            @copy($source, $destination);
        }, $pendingSelfUpdate);
    }

    echo "Arquivos atualizados. Limpando temporários...\n";
    $cleanup();
    $tmpPath = null;

    echo "Executando migrações...\n";
    runCommand(PHP_BINARY . ' ' . escapeshellarg(__DIR__ . DIRECTORY_SEPARATOR . 'migrate.php'));

    echo "Atualização concluída com sucesso.\n";
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Erro: ' . $exception->getMessage() . PHP_EOL);
    $cleanup();
    exit(1);
}

function extractPackage(string $package, string $targetDir): void
{
    $zip = new ZipArchive();
    if ($zip->open($package) !== true) {
        throw new RuntimeException('Não foi possível abrir o pacote ZIP.');
    }

    if (!$zip->extractTo($targetDir)) {
        $zip->close();
        throw new RuntimeException('Falha ao extrair o pacote.');
    }

    $zip->close();
}

function validateFileList(array $files): void
{
    foreach ($files as $file) {
        if (!is_string($file) || $file === '') {
            throw new RuntimeException('Lista de arquivos inválida no manifest.');
        }
        if (str_contains($file, '..')) {
            throw new RuntimeException('Manifest contém caminhos inválidos.');
        }
    }
}

function createBackupZip(string $basePath, array $files): string
{
    $backupDir = $basePath . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'backups';
    if (!is_dir($backupDir) && !mkdir($backupDir, 0775, true) && !is_dir($backupDir)) {
        throw new RuntimeException('Não foi possível criar diretório de backups.');
    }

    $backupPath = $backupDir . DIRECTORY_SEPARATOR . 'code_' . date('Ymd_His') . '.zip';
    $zip = new ZipArchive();
    if ($zip->open($backupPath, ZipArchive::CREATE) !== true) {
        throw new RuntimeException('Não foi possível criar o arquivo de backup.');
    }

    foreach ($files as $relative) {
        $absolute = $basePath . DIRECTORY_SEPARATOR . convertToFilesystemPath($relative);
        if (!is_file($absolute)) {
            continue;
        }

        $zip->addFile($absolute, normalizeRelative($relative));
    }

    $zip->close();
    return $backupPath;
}

function ensureDirectory(string $path): void
{
    if ($path === '' || is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException("Não foi possível criar o diretório {$path}.");
    }
}

function recursiveDelete(string $path): void
{
    if (!is_dir($path)) {
        return;
    }

    $items = array_diff(scandir($path) ?: [], ['.', '..']);
    foreach ($items as $item) {
        $fullPath = $path . DIRECTORY_SEPARATOR . $item;
        if (is_dir($fullPath)) {
            recursiveDelete($fullPath);
            continue;
        }
        @unlink($fullPath);
    }

    @rmdir($path);
}

function convertToFilesystemPath(string $relative): string
{
    return str_replace('/', DIRECTORY_SEPARATOR, $relative);
}

function normalizeRelative(string $path): string
{
    return str_replace('\\', '/', ltrim($path, '\\/'));
}

function runCommand(string $command): void
{
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException("Comando falhou: {$command}");
    }
}
