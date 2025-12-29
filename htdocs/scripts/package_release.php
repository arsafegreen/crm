<?php

declare(strict_types=1);

use App\Repositories\SystemReleaseRepository;
use Dotenv\Dotenv;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Support/helpers.php';

if (file_exists(__DIR__ . '/../.env')) {
    Dotenv::createImmutable(realpath(__DIR__ . '/..') ?: __DIR__ . '/..')->load();
}

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Este script deve ser executado via CLI.\n");
    exit(1);
}

$basePath = realpath(__DIR__ . '/..');
if ($basePath === false) {
    fwrite(STDERR, "Não foi possível resolver o caminho base do projeto.\n");
    exit(1);
}

[$version, $options] = parseArguments($argv);
$skipVendor = $options['skip_vendor'] ?? false;
$notes = $options['notes'] ?? null;

$releaseDir = storage_path('releases');
if (!is_dir($releaseDir) && !mkdir($releaseDir, 0775, true) && !is_dir($releaseDir)) {
    fwrite(STDERR, "Falha ao criar diretório de releases em {$releaseDir}.\n");
    exit(1);
}

$zipPath = $releaseDir . DIRECTORY_SEPARATOR . $version . '.zip';
if (file_exists($zipPath)) {
    fwrite(STDERR, "Já existe um pacote chamado {$zipPath}. Escolha outro identificador.\n");
    exit(1);
}

$includePaths = [
    'app',
    'bootstrap',
    'config',
    'docs',
    'public',
    'resources',
    'scripts',
    'vendor',
    'composer.json',
    'composer.lock',
    'Caddyfile',
    'README.md',
];

if ($skipVendor) {
    $includePaths = array_values(array_filter($includePaths, static fn(string $path): bool => $path !== 'vendor'));
}

$files = [];
$zip = new ZipArchive();
if ($zip->open($zipPath, ZipArchive::CREATE) !== true) {
    fwrite(STDERR, "Não foi possível criar o arquivo {$zipPath}.\n");
    exit(1);
}

try {
    foreach ($includePaths as $path) {
        $fullPath = $basePath . DIRECTORY_SEPARATOR . $path;
        if (!file_exists($fullPath)) {
            continue;
        }

        addPathToZip($zip, $basePath, $fullPath, $path, $files);
    }

    sort($files);

    $manifest = [
        'version' => $version,
        'created_at' => date('c'),
        'base_path' => $basePath,
        'git_commit' => detectGitCommit($basePath),
        'php_version' => PHP_VERSION,
        'include_vendor' => !$skipVendor,
        'file_count' => count($files),
        'notes' => $notes,
        'files' => $files,
    ];

    $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    if ($manifestJson === false) {
        throw new RuntimeException('Falha ao gerar manifest.');
    }

    $zip->addFromString('release_manifest.json', $manifestJson);
} catch (Throwable $exception) {
    $zip->close();
    @unlink($zipPath);
    throw $exception;
}

$zip->close();

$fileSize = filesize($zipPath) ?: 0;
$fileHash = hash_file('sha256', $zipPath) ?: '';
$relativePath = trim(str_replace(base_path() . DIRECTORY_SEPARATOR, '', $zipPath), '\\/');

$repository = new SystemReleaseRepository();

$existing = $repository->findByVersion($version);
if ($existing !== null) {
    fwrite(STDERR, "Já existe uma release registrada com a versão {$version}. Escolha outro identificador.\n");
    @unlink($zipPath);
    exit(1);
}

$manifestData = $manifest;
$manifestData['file_hash'] = $fileHash;
$manifestData['file_size'] = $fileSize;

$releaseId = $repository->create([
    'version' => $version,
    'status' => 'available',
    'origin' => 'local',
    'notes' => $notes,
    'file_name' => $relativePath,
    'file_size' => $fileSize,
    'file_hash' => $fileHash,
    'include_vendor' => $manifest['include_vendor'] ? 1 : 0,
    'git_commit' => $manifest['git_commit'],
    'php_version' => PHP_VERSION,
    'manifest' => json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
]);

echo "Release #{$releaseId} ({$version}) criada em {$zipPath}\n";
exit(0);

function parseArguments(array $argv): array
{
    $args = $argv;
    array_shift($args);

    $versionArg = null;
    $options = [
        'skip_vendor' => false,
    ];

    foreach ($args as $arg) {
        if ($arg === '--skip-vendor') {
            $options['skip_vendor'] = true;
            continue;
        }

        if (str_starts_with($arg, '--notes=')) {
            $options['notes'] = trim(substr($arg, 8));
            continue;
        }

        if (str_starts_with($arg, '--')) {
            continue;
        }

        if ($versionArg === null) {
            $versionArg = $arg;
            continue;
        }
    }

    $version = $versionArg !== null ? sanitizeVersion($versionArg) : 'release_' . date('Ymd_His');
    return [$version, $options];
}

function sanitizeVersion(string $value): string
{
    $sanitized = preg_replace('/[^a-zA-Z0-9._-]/', '_', $value);
    return $sanitized === '' ? 'release_' . date('Ymd_His') : $sanitized;
}

function addPathToZip(ZipArchive $zip, string $basePath, string $fullPath, string $relativePath, array &$files): void
{
    if (is_file($fullPath)) {
        $zip->addFile($fullPath, normalizeRelative($relativePath));
        $files[] = normalizeRelative($relativePath);
        return;
    }

    if (!is_dir($fullPath)) {
        return;
    }

    $directoryIterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($fullPath, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($directoryIterator as $item) {
        $realPath = $item->getPathname();
        $relative = normalizeRelative(substr($realPath, strlen($basePath) + 1));

        if ($item->isDir()) {
            $zip->addEmptyDir($relative);
            continue;
        }

        $zip->addFile($realPath, $relative);
        $files[] = $relative;
    }
}

function normalizeRelative(string $path): string
{
    return str_replace('\\', '/', ltrim($path, '\\/'));
}

function detectGitCommit(string $basePath): ?string
{
    $gitDir = $basePath . DIRECTORY_SEPARATOR . '.git';
    if (!is_dir($gitDir)) {
        return null;
    }

    $descriptor = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open(
        ['git', '-C', $basePath, 'rev-parse', 'HEAD'],
        $descriptor,
        $pipes
    );

    if (!is_resource($process)) {
        return null;
    }

    $output = stream_get_contents($pipes[1]);
    $error = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    $exitCode = proc_close($process);
    if ($exitCode !== 0) {
        return null;
    }

    $commit = trim((string)$output);
    return $commit === '' ? null : $commit;
}
