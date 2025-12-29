<?php

declare(strict_types=1);

namespace App\Services\Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;
use ZipArchive;
use Throwable;

final class BackupService
{
    private string $basePath;
    private string $backupDir;
    private string $manifestDir;

    /**
     * @var string[]
     */
    private array $staticExclusions;

    public function __construct()
    {
        $this->basePath = base_path();
        $this->backupDir = storage_path('backups');
        $this->manifestDir = storage_path('backups/manifests');
        $this->staticExclusions = [
            $this->backupDir,
            base_path('vendor/composer'),
            base_path('node_modules'),
            storage_path('logs'),
            storage_path('tmp'),
            base_path('.git'),
        ];

        $this->ensureDirectory($this->backupDir);
        $this->ensureDirectory($this->manifestDir);
    }

    /**
     * Lista manifestos existentes (ordenados do mais recente para o mais antigo).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listSnapshots(): array
    {
        $files = glob($this->manifestDir . DIRECTORY_SEPARATOR . '*.json') ?: [];
        $snapshots = [];

        foreach ($files as $file) {
            $manifest = $this->decodeManifest($file);
            if ($manifest === null) {
                continue;
            }
            $snapshots[] = $manifest;
        }

        usort($snapshots, static function (array $a, array $b): int {
            return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
        });

        return $snapshots;
    }

    /**
     * Remove snapshots antigos mantendo os N últimos full e suas cadeias.
     * Se $maxTotalBytes for definido, remove mais antigos até atingir o limite.
     *
     * @return array{removed: array<int, string>, kept: array<int, string>}
     */
    public function prune(int $keepFull = 2, ?int $maxTotalBytes = null): array
    {
        $snapshots = $this->listSnapshots();
        $byId = [];
        foreach ($snapshots as $snap) {
            $id = (string)($snap['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $byId[$id] = $snap;
        }

        // Identifica os full mais recentes a manter.
        $fulls = array_values(array_filter($snapshots, static fn ($s) => ($s['type'] ?? null) === 'full'));
        usort($fulls, static fn ($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
        $keepFullIds = array_slice(array_map(static fn ($f) => (string)$f['id'], $fulls), 0, max(1, $keepFull));

        $keep = [];
        $remove = [];

        foreach ($byId as $id => $_) {
            $root = $this->chainRoot($id, $byId);
            if ($root !== null && in_array($root, $keepFullIds, true)) {
                $keep[$id] = true;
            } else {
                $remove[$id] = true;
            }
        }

        // Aplica limite de tamanho (opcional) removendo mais antigos.
        if ($maxTotalBytes !== null && $maxTotalBytes > 0) {
            $keptSnapshots = array_values(array_filter($snapshots, fn ($s) => isset($keep[$s['id'] ?? ''])));
            usort($keptSnapshots, static fn ($a, $b) => strcmp((string)($a['created_at'] ?? ''), (string)($b['created_at'] ?? '')));

            $total = 0;
            $sizes = [];
            $fullCount = 0;
            foreach ($keptSnapshots as $snap) {
                $id = (string)($snap['id'] ?? '');
                $size = $this->snapshotSize($id);
                $sizes[$id] = $size;
                $total += $size;
                if (($snap['type'] ?? null) === 'full') {
                    $fullCount++;
                }
            }

            foreach ($keptSnapshots as $snap) {
                if ($total <= $maxTotalBytes) {
                    break;
                }

                $id = (string)($snap['id'] ?? '');
                // Garante pelo menos um full mantido.
                if (($snap['type'] ?? null) === 'full' && $fullCount <= 1) {
                    continue;
                }

                unset($keep[$id]);
                $remove[$id] = true;
                $total -= $sizes[$id] ?? 0;
                if (($snap['type'] ?? null) === 'full') {
                    $fullCount--;
                }
            }
        }

        $removedIds = array_keys($remove);
        foreach ($removedIds as $id) {
            $this->deleteSnapshot($id);
        }

        return [
            'removed' => $removedIds,
            'kept' => array_keys($keep),
        ];
    }

    /**
     * Cria um backup completo.
     *
     * @return array{manifest: array<string, mixed>, zip_path: string}
     */
    public function createFull(bool $withMedia = false, string $note = ''): array
    {
        $id = 'full_' . date('Ymd_His');
        $excludes = $this->buildExclusions($withMedia);
        $files = $this->buildFileIndex($excludes);

        $manifest = [
            'id' => $id,
            'type' => 'full',
            'created_at' => gmdate('c'),
            'base_id' => null,
            'with_media' => $withMedia,
            'excludes' => $excludes,
            'notes' => $note,
            'delta' => ['changed' => array_keys($files), 'removed' => []],
            'files' => $files,
        ];

        $zipPath = $this->writeSnapshot($manifest, array_keys($files));

        return ['manifest' => $manifest, 'zip_path' => $zipPath];
    }

    /**
     * Cria um backup incremental com base em um snapshot anterior.
     *
     * @return array{manifest: array<string, mixed>, zip_path: string}
     */
    public function createIncremental(string $baseId, ?bool $withMedia = null, string $note = ''): array
    {
        $base = $this->loadManifest($baseId);
        if ($base === null) {
            throw new RuntimeException("Manifesto base {$baseId} não encontrado.");
        }

        $withMedia = $withMedia ?? (bool)($base['with_media'] ?? false);
        $excludes = $this->buildExclusions($withMedia);

        $currentIndex = $this->buildFileIndex($excludes);
        $baseIndex = is_array($base['files'] ?? null) ? $base['files'] : [];

        $changed = [];
        foreach ($currentIndex as $path => $meta) {
            $baseMeta = $baseIndex[$path] ?? null;
            if ($baseMeta === null || ($baseMeta['hash'] ?? '') !== ($meta['hash'] ?? '')) {
                $changed[] = $path;
            }
        }

        $removed = array_values(array_diff(array_keys($baseIndex), array_keys($currentIndex)));

        $id = 'incr_' . date('Ymd_His');

        $manifest = [
            'id' => $id,
            'type' => 'incremental',
            'created_at' => gmdate('c'),
            'base_id' => $baseId,
            'with_media' => $withMedia,
            'excludes' => $excludes,
            'notes' => $note,
            'delta' => ['changed' => $changed, 'removed' => $removed],
            'files' => $currentIndex,
        ];

        $zipPath = $this->writeSnapshot($manifest, $changed);

        return ['manifest' => $manifest, 'zip_path' => $zipPath];
    }

    /**
     * Restaura uma cadeia completa (full + incrementais) no destino.
     *
     * @return array{chain: array<int, array<string, mixed>>, destination: string}
     */
    public function restore(string $targetId, string $destination, bool $force = false): array
    {
        $chain = $this->resolveChain($targetId);
        if ($chain === []) {
            throw new RuntimeException('Cadeia de snapshots não encontrada.');
        }

        $this->prepareDestination($destination, $force);

        foreach ($chain as $manifest) {
            $zipPath = $this->snapshotPath((string)$manifest['id']);
            if (!is_file($zipPath)) {
                throw new RuntimeException("Arquivo de snapshot ausente: {$zipPath}");
            }

            $this->applySnapshot($zipPath, $destination);

            $removed = $manifest['delta']['removed'] ?? [];
            foreach ($removed as $relative) {
                $this->removePath($destination, $relative);
            }
        }

        return ['chain' => $chain, 'destination' => $destination];
    }

    /**
     * Resolve a cadeia completa (do full mais antigo até o alvo).
     *
     * @return array<int, array<string, mixed>>
     */
    public function resolveChain(string $targetId): array
    {
        $ordered = [];
        $seen = [];
        $current = $targetId;

        while ($current !== null) {
            if (isset($seen[$current])) {
                throw new RuntimeException('Cadeia de manifests em loop.');
            }
            $seen[$current] = true;

            $manifest = $this->loadManifest($current);
            if ($manifest === null) {
                throw new RuntimeException("Manifesto {$current} não encontrado.");
            }

            array_unshift($ordered, $manifest);
            $current = $manifest['base_id'] ?? null;
        }

        $first = $ordered[0] ?? [];
        if (($first['type'] ?? null) !== 'full') {
            throw new RuntimeException('A cadeia deve iniciar em um snapshot completo.');
        }

        return $ordered;
    }

    private function applySnapshot(string $zipPath, string $destination): void
    {
        $zip = new ZipArchive();
        if ($zip->open($zipPath) !== true) {
            throw new RuntimeException("Não foi possível abrir {$zipPath}");
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) {
                continue;
            }
            $name = $stat['name'] ?? '';
            if ($name === '' || $name === 'manifest.json') {
                continue;
            }
            $zip->extractTo($destination, [$name]);
        }

        $zip->close();
    }

    private function prepareDestination(string $destination, bool $force): void
    {
        if (!is_dir($destination)) {
            if (!mkdir($destination, 0775, true) && !is_dir($destination)) {
                throw new RuntimeException("Não foi possível criar {$destination}");
            }
            return;
        }

        $iterator = new FilesystemIterator($destination, FilesystemIterator::SKIP_DOTS);
        if ($iterator->valid() && !$force) {
            throw new RuntimeException('Destino não está vazio. Use force para continuar.');
        }

        if ($iterator->valid() && $force) {
            $this->clearDirectory($destination);
        }
    }

    private function clearDirectory(string $path): void
    {
        $iterator = new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $item) {
            $target = $item->getPathname();
            if ($item->isDir() && !$item->isLink()) {
                $this->clearDirectory($target);
                rmdir($target);
            } else {
                unlink($target);
            }
        }
    }

    private function removePath(string $destination, string $relative): void
    {
        $normalized = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $relative);
        $target = rtrim($destination, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $normalized;
        if (is_file($target)) {
            unlink($target);
            return;
        }

        if (is_dir($target)) {
            $this->clearDirectory($target);
            rmdir($target);
        }
    }

    /**
     * @return array<string, array{hash: string, size: int, mtime: int}>
     */
    private function buildFileIndex(array $excludes): array
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->basePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );

        $index = [];

        /** @var SplFileInfo $item */
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                continue;
            }

            $path = (string)$item->getPathname();
            if ($this->isExcluded($path, $excludes)) {
                continue;
            }

            $relative = ltrim(str_replace($this->basePath, '', $path), DIRECTORY_SEPARATOR);
            $relative = str_replace('\\', '/', $relative);

            if (!is_readable($path)) {
                continue;
            }

            $hash = @sha1_file($path);
            if ($hash === false) {
                continue;
            }

            $index[$relative] = [
                'hash' => $hash,
                'size' => $item->getSize(),
                'mtime' => $item->getMTime(),
            ];
        }

        return $index;
    }

    private function isExcluded(string $path, array $excludes): bool
    {
        $normalized = $this->normalizePath($path);
        foreach ($excludes as $exclude) {
            $prefix = $this->normalizePath((string)$exclude);
            if ($prefix !== '' && str_starts_with($normalized, $prefix)) {
                return true;
            }
        }

        $normalizedFwd = str_replace(DIRECTORY_SEPARATOR, '/', $normalized);
        if (str_contains($normalizedFwd, 'storage/whatsapp-web/') && str_contains($normalizedFwd, '/session/Default/Network/')) {
            return true;
        }

        $basename = basename($normalized);
        if (in_array($basename, ['.DS_Store', 'Thumbs.db', '0'], true)) {
            return true;
        }

        return false;
    }

    private function buildExclusions(bool $withMedia): array
    {
        $excludes = $this->staticExclusions;

        if (!$withMedia) {
            $excludes[] = storage_path('whatsapp-media');
            $excludes[] = storage_path('whatsapp-web');
        }

        return $excludes;
    }

    private function writeSnapshot(array $manifest, array $paths): string
    {
        $zipPath = $this->snapshotPath((string)$manifest['id']);
        $zip = new ZipArchive();

        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("Falha ao criar zip em {$zipPath}");
        }

        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($manifestJson === false) {
            throw new RuntimeException('Falha ao serializar manifesto.');
        }

        $zip->addFromString('manifest.json', $manifestJson);

        foreach ($paths as $relative) {
            $relative = (string)$relative;
            $absolute = $this->basePath . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($absolute)) {
                continue;
            }
            $zip->addFile($absolute, $relative);
        }

        $closed = @$zip->close();
        if ($closed !== true) {
            throw new RuntimeException('Falha ao finalizar o arquivo ZIP.');
        }

        $this->saveManifest($manifest);

        return $zipPath;
    }

    private function saveManifest(array $manifest): void
    {
        $path = $this->manifestDir . DIRECTORY_SEPARATOR . $manifest['id'] . '.json';
        $json = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('Falha ao salvar manifesto.');
        }

        if (file_put_contents($path, $json) === false) {
            throw new RuntimeException("Não foi possível gravar {$path}");
        }
    }

    private function loadManifest(string $id): ?array
    {
        $path = $this->manifestDir . DIRECTORY_SEPARATOR . $id . '.json';
        return $this->decodeManifest($path);
    }

    private function decodeManifest(string $path): ?array
    {
        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            return null;
        }

        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($data)) {
            return null;
        }

        return $data;
    }

    private function chainRoot(string $id, array $byId): ?string
    {
        $current = $id;
        $guard = 0;

        while ($current !== null && $guard < 1000) {
            $guard++;
            $manifest = $byId[$current] ?? null;
            if (!is_array($manifest)) {
                return null;
            }

            $base = $manifest['base_id'] ?? null;
            if ($base === null) {
                return $current;
            }

            $current = is_string($base) ? $base : null;
        }

        return null;
    }

    private function snapshotSize(string $id): int
    {
        $path = $this->snapshotPath($id);
        if (!is_file($path)) {
            return 0;
        }

        return (int)filesize($path);
    }

    private function deleteSnapshot(string $id): void
    {
        $zipPath = $this->snapshotPath($id);
        if (is_file($zipPath)) {
            @unlink($zipPath);
        }

        $manifestPath = $this->manifestDir . DIRECTORY_SEPARATOR . $id . '.json';
        if (is_file($manifestPath)) {
            @unlink($manifestPath);
        }
    }

    private function snapshotPath(string $id): string
    {
        return $this->backupDir . DIRECTORY_SEPARATOR . $id . '.zip';
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        if (!mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException("Não foi possível criar {$path}");
        }
    }

    private function normalizePath(string $path): string
    {
        $normalized = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path);
        return rtrim($normalized, DIRECTORY_SEPARATOR);
    }
}
