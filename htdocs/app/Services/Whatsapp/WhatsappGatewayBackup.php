<?php

declare(strict_types=1);

namespace App\Services\Whatsapp;

use RuntimeException;
use ZipArchive;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use PharData;
use Phar;
use Throwable;

use function bin2hex;
use function date;
use function dirname;
use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function glob;
use function is_dir;
use function is_file;
use function json_decode;
use function json_encode;
use function mkdir;
use function pathinfo;
use function random_bytes;
use function storage_path;
use function preg_replace;
use function strlen;
use function strtolower;
use function time;
use function trim;
use function array_key_exists;
use function array_values;

final class WhatsappGatewayBackup
{
    private string $basePath;
    private int $compressAfterSeconds;

    public function __construct(?string $basePath = null, int $compressAfterSeconds = 86400)
    {
        $this->basePath = $basePath ?? storage_path('whatsapp_gateway_backups');
        $this->compressAfterSeconds = max(3600, $compressAfterSeconds);
    }

    /** @param array<string,mixed> $payload */
    public function backupIncoming(array $payload): void
    {
        $this->persist('incoming', $payload);
    }

    /** @param array<string,mixed> $payload */
    public function backupOutgoing(array $payload): void
    {
        $this->persist('outgoing', $payload);
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function loadArchiveEntries(string $archivePath): array
    {
        if (!is_file($archivePath)) {
            throw new RuntimeException('Arquivo de backup não encontrado para restauração.');
        }

        $workingDir = $this->basePath . DIRECTORY_SEPARATOR . 'restore_' . bin2hex(random_bytes(4));
        $this->ensureDirectory($workingDir);

        $localArchive = $workingDir . DIRECTORY_SEPARATOR . basename($archivePath);
        @copy($archivePath, $localArchive);

        $extension = strtolower((string)pathinfo($localArchive, PATHINFO_EXTENSION));
        $isTarGz = substr($localArchive, -7) === '.tar.gz';

        if ($extension === 'zip') {
            $zip = new ZipArchive();
            if ($zip->open($localArchive) !== true) {
                throw new RuntimeException('Não foi possível abrir o arquivo ZIP do backup.');
            }
            $zip->extractTo($workingDir);
            $zip->close();
        } elseif ($isTarGz || $extension === 'tar') {
            $tarPath = $localArchive;
            if ($isTarGz) {
                $gz = new PharData($localArchive);
                $tarPath = preg_replace('/\.gz$/', '', $localArchive) ?? ($localArchive . '.tar');
                $gz->decompress();
            }

            $tar = new PharData($tarPath);
            $tar->extractTo($workingDir, null, true);
        } else {
            // Copia simples para diretório temporário para leitura consistente
            $target = $workingDir . DIRECTORY_SEPARATOR . basename($localArchive);
            @copy($localArchive, $target);
        }

        $entries = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($workingDir, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($iterator as $fileInfo) {
            if (!$fileInfo->isFile()) {
                continue;
            }
            if (strtolower($fileInfo->getExtension()) !== 'json') {
                continue;
            }
            $content = file_get_contents($fileInfo->getPathname());
            if ($content === false || trim($content) === '') {
                continue;
            }
            $decoded = json_decode($content, true);
            if (!is_array($decoded)) {
                continue;
            }

            // Aggregated per-contact backup with message list
            if (isset($decoded['contact'], $decoded['messages']) && is_array($decoded['contact']) && is_array($decoded['messages'])) {
                $contact = $decoded['contact'];
                $messages = $decoded['messages'];

                foreach ($messages as $message) {
                    if (!is_array($message)) {
                        continue;
                    }

                    $ingest = [
                        'direction' => $message['direction'] ?? 'incoming',
                        'phone' => (string)($message['phone'] ?? ($contact['phone'] ?? '')),
                        'message' => (string)($message['message'] ?? ''),
                        'contact_name' => $message['contact_name'] ?? ($contact['name'] ?? null),
                        'line_label' => $message['line_label'] ?? ($contact['line_label'] ?? null),
                        'timestamp' => $message['timestamp'] ?? ($contact['last_seen'] ?? null),
                        'message_type' => $message['message_type'] ?? ($message['media']['type'] ?? null),
                        'metadata' => $this->mergeMetadata(
                            $contact['meta'] ?? [],
                            $message['metadata'] ?? []
                        ),
                        'media' => $message['media'] ?? null,
                    ];

                    $decodedStub = ['media' => ['stored_path' => $message['media']['stored_path'] ?? null]];
                    $entries[] = $this->hydrateMediaForIngest($ingest, $decodedStub);
                }

                continue;
            }

            $ingest = $decoded['ingest'] ?? $decoded;
            if (!is_array($ingest)) {
                continue;
            }

            $ingest = $this->hydrateMediaForIngest($ingest, $decoded);
            $entries[] = $ingest;
        }

        return $entries;
    }

    /**
     * @return array{
     *   date:string,
     *   total:int,
     *   lines:array<string,array{incoming:int,outgoing:int,total:int}>,
     *   errors:array<int,string>
     * }
     */
    public function summarize(?int $timestamp = null): array
    {
        $targetDate = date('Y-m-d', $timestamp ?? time());
        $folder = $this->basePath . DIRECTORY_SEPARATOR . $targetDate;
        $tarGz = $folder . '.tar.gz';
        $tar = $folder . '.tar';

        $errors = [];
        $lines = [];
        $total = 0;

        $iterator = null;

        if (is_dir($folder)) {
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, RecursiveDirectoryIterator::SKIP_DOTS));
        } elseif (is_file($tarGz) || is_file($tar)) {
            try {
                $working = $this->basePath . DIRECTORY_SEPARATOR . 'summary_' . bin2hex(random_bytes(4));
                $this->ensureDirectory($working);
                $archivePath = is_file($tarGz) ? $tarGz : $tar;
                $tmpCopy = $working . DIRECTORY_SEPARATOR . basename($archivePath);
                @copy($archivePath, $tmpCopy);
                $archiveExt = strtolower((string)pathinfo($tmpCopy, PATHINFO_EXTENSION));
                $isTarGz = substr($tmpCopy, -7) === '.tar.gz';

                if ($isTarGz || $archiveExt === 'tar') {
                    $tarPath = $tmpCopy;
                    if ($isTarGz) {
                        $gz = new PharData($tmpCopy);
                        $tarPath = preg_replace('/\.gz$/', '', $tmpCopy) ?? ($tmpCopy . '.tar');
                        $gz->decompress();
                    }
                    $tarData = new PharData($tarPath);
                    $tarData->extractTo($working, null, true);
                }

                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($working, RecursiveDirectoryIterator::SKIP_DOTS));
            } catch (Throwable $exception) {
                $errors[] = 'Falha ao ler arquivo compactado: ' . $exception->getMessage();
                $iterator = null;
            }
        }

        if ($iterator !== null) {
            foreach ($iterator as $fileInfo) {
                if (!$fileInfo->isFile() || strtolower($fileInfo->getExtension()) !== 'json') {
                    continue;
                }
                $content = file_get_contents($fileInfo->getPathname());
                if ($content === false || trim($content) === '') {
                    continue;
                }
                $decoded = json_decode($content, true);
                if (!is_array($decoded)) {
                    continue;
                }
                $direction = strtolower((string)($decoded['direction'] ?? $decoded['ingest']['direction'] ?? ''));
                if (!in_array($direction, ['incoming', 'outgoing'], true)) {
                    continue;
                }
                $label = (string)($decoded['line_label'] ?? $decoded['ingest']['line_label'] ?? 'sem_rótulo');
                $label = trim($label) !== '' ? $label : 'sem_rótulo';

                if (!array_key_exists($label, $lines)) {
                    $lines[$label] = ['incoming' => 0, 'outgoing' => 0, 'total' => 0];
                }
                $lines[$label][$direction]++;
                $lines[$label]['total']++;
                $total++;
            }
        }

        ksort($lines);

        return [
            'date' => $targetDate,
            'total' => $total,
            'lines' => $lines,
            'errors' => array_values($errors),
        ];
    }

    /** @param array<string,mixed> $payload */
    private function persist(string $direction, array $payload): void
    {
        $timestamp = isset($payload['meta']['timestamp']) ? (int)$payload['meta']['timestamp'] : time();
        $dateFolder = date('Y-m-d', $timestamp);
        $targetDir = $this->basePath . DIRECTORY_SEPARATOR . $dateFolder;
        $this->ensureDirectory($targetDir);

        $contactSnapshot = $this->buildContactSnapshot($payload, $direction, $timestamp);
        $contactKey = $this->resolveContactKey($contactSnapshot);
        $contactFile = $targetDir . DIRECTORY_SEPARATOR . 'contact-' . $contactKey . '.json';

        $existing = $this->loadContactRecord($contactFile);
        $existingContact = $existing['contact'] ?? [];
        $messages = isset($existing['messages']) && is_array($existing['messages']) ? $existing['messages'] : [];

        $identifier = $this->resolveIdentifier($payload);
        $fileBase = $this->slugify($contactKey . '-' . $identifier);

        $mediaInfo = $this->persistMedia($payload['media'] ?? null, $targetDir, $fileBase);

        $messageType = $mediaInfo['meta']['type'] ?? ($payload['meta']['message_type'] ?? ($payload['media']['type'] ?? null));
        $messageEntry = [
            'id' => $identifier,
            'direction' => $direction,
            'phone' => (string)($payload['phone'] ?? ''),
            'message' => (string)($payload['message'] ?? ''),
            'timestamp' => $timestamp,
            'contact_name' => $payload['contact_name'] ?? ($payload['meta']['contact_name'] ?? null),
            'line_label' => $payload['line_label'] ?? ($payload['meta']['line_label'] ?? null),
            'message_type' => $messageType,
            'metadata' => $payload['meta'] ?? [],
            'media' => $mediaInfo['ingest'] ?? null,
            'media_meta' => $mediaInfo['meta'] ?? null,
            'raw' => $payload['raw'] ?? null,
        ];

        $messages = $this->upsertMessage($messages, $messageEntry);

        $mergedContact = $this->mergeContactSnapshot($existingContact, $contactSnapshot);

        $record = [
            'contact' => $mergedContact,
            'messages' => array_values($messages),
        ];

        file_put_contents($contactFile, json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        $this->compressOldFolders();
    }

    /**
     * @param array<string,mixed>|null $media
     * @return array{meta:array<string,mixed>|null,ingest:array<string,mixed>|null}
     */
    private function persistMedia(?array $media, string $targetDir, string $fileBase): array
    {
        if ($media === null || $media === []) {
            return ['meta' => null, 'ingest' => null];
        }

        $meta = [
            'type' => $media['type'] ?? ($media['mimetype'] ?? $media['mime'] ?? null),
            'mimetype' => $media['mimetype'] ?? $media['mime'] ?? null,
            'original_name' => $media['original_name'] ?? $media['filename'] ?? null,
            'caption' => $media['caption'] ?? null,
            'size' => isset($media['size']) && is_numeric($media['size']) ? (int)$media['size'] : null,
        ];

        $binary = null;
        $rawData = $media['data'] ?? null;
        if (is_string($rawData) && trim($rawData) !== '') {
            $binary = base64_decode($rawData, true);
            if ($binary === false) {
                $binary = null;
            }
        }

        $downloadUrl = $media['url'] ?? null;
        if ($binary === null && is_string($downloadUrl) && trim($downloadUrl) !== '') {
            $binary = @file_get_contents($downloadUrl);
        }

        $storedPath = null;
        if ($binary !== null) {
            $ext = $this->guessExtension($meta['mimetype'] ?? null, $meta['type'] ?? null, $meta['original_name'] ?? null);
            $storedPath = $targetDir . DIRECTORY_SEPARATOR . $fileBase . ($ext !== '' ? '.' . $ext : '.bin');
            file_put_contents($storedPath, $binary);
            $meta['stored_path'] = $storedPath;
            if ($meta['size'] === null) {
                $meta['size'] = strlen($binary);
            }
        }

        $ingestMedia = $media;
        $ingestMedia['stored_path'] = $storedPath;
        if ($storedPath !== null) {
            unset($ingestMedia['url']);
            unset($ingestMedia['data']);
        }

        return [
            'meta' => $meta,
            'ingest' => $ingestMedia,
        ];
    }

    private function compressOldFolders(): void
    {
        $cutoffDate = date('Y-m-d', time() - $this->compressAfterSeconds);
        $target = $this->basePath . DIRECTORY_SEPARATOR . $cutoffDate;
        if (!is_dir($target)) {
            return;
        }

        $tarGzPath = $target . '.tar.gz';
        if (is_file($tarGzPath)) {
            return;
        }

        $tarPath = $target . '.tar';
        if (is_file($tarPath)) {
            @unlink($tarPath);
        }

        try {
            $tar = new PharData($tarPath);

            $files = glob($target . DIRECTORY_SEPARATOR . '*');
            if ($files !== false) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        $tar->addFile($file, basename($file));
                    }
                }
            }

            $tar->compress(Phar::GZ);
        } catch (Throwable $exception) {
            // Em caso de falha, deixa a pasta sem compactar para evitar perda
            return;
        } finally {
            if (is_file($tarPath)) {
                @unlink($tarPath);
            }
        }
    }

    /**
     * @param array<string,mixed> $ingest
     * @param array<string,mixed> $decoded
     * @return array<string,mixed>
     */
    private function hydrateMediaForIngest(array $ingest, array $decoded): array
    {
        $media = $ingest['media'] ?? null;
        if (!is_array($media)) {
            return $ingest;
        }

        $storedPath = $media['stored_path'] ?? ($decoded['media']['stored_path'] ?? null);
        if (is_string($storedPath) && file_exists($storedPath)) {
            $binary = @file_get_contents($storedPath);
            if ($binary !== false) {
                $media['data'] = base64_encode($binary);
                if (!isset($media['filename'])) {
                    $media['filename'] = basename($storedPath);
                }
            }
        }

        unset($media['stored_path']);
        $ingest['media'] = $media;

        return $ingest;
    }

    /**
     * Build a contact snapshot with sender hints to keep only one contact record per day.
     *
     * @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function buildContactSnapshot(array $payload, string $direction, int $timestamp): array
    {
        $meta = isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : [];
        $phone = (string)($payload['phone'] ?? ($meta['phone'] ?? ''));
        $contactName = $payload['contact_name'] ?? ($meta['contact_name'] ?? null);
        $profilePhoto = $meta['profile_photo'] ?? null;
        $lineLabel = $payload['line_label'] ?? ($meta['line_label'] ?? null);

        return [
            'phone' => $phone,
            'display_phone' => format_phone($phone),
            'name' => $contactName,
            'profile_photo' => $profilePhoto,
            'instance' => $payload['instance'] ?? null,
            'line_label' => $lineLabel,
            'first_seen' => $timestamp,
            'last_seen' => $timestamp,
            'last_direction' => $direction,
            'meta' => $meta,
        ];
    }

    /** @param array<string,mixed> $contact */
    private function resolveContactKey(array $contact): string
    {
        $phoneDigits = preg_replace('/\D+/', '', (string)($contact['phone'] ?? '')) ?: '';
        $label = (string)($contact['line_label'] ?? '');

        $candidate = $phoneDigits !== '' ? $phoneDigits : ($label !== '' ? $label : 'contato');
        return $this->slugify($candidate);
    }

    /**
     * Merge existing contact snapshot with incoming hints, preferring newer values only when missing.
     *
     * @param array<string,mixed> $existing
     * @param array<string,mixed> $incoming
     * @return array<string,mixed>
     */
    private function mergeContactSnapshot(array $existing, array $incoming): array
    {
        if ($existing === []) {
            return $incoming;
        }

        $merged = $existing;
        $merged['last_seen'] = max((int)($existing['last_seen'] ?? 0), (int)($incoming['last_seen'] ?? 0));
        $merged['first_seen'] = min((int)($existing['first_seen'] ?? $merged['last_seen']), (int)($incoming['first_seen'] ?? $merged['last_seen']));
        $merged['last_direction'] = $incoming['last_direction'] ?? ($existing['last_direction'] ?? null);

        if (($merged['name'] ?? '') === '' && ($incoming['name'] ?? '') !== '') {
            $merged['name'] = $incoming['name'];
        }
        if (($merged['profile_photo'] ?? '') === '' && ($incoming['profile_photo'] ?? '') !== '') {
            $merged['profile_photo'] = $incoming['profile_photo'];
        }
        if (($merged['display_phone'] ?? '') === '' && ($incoming['display_phone'] ?? '') !== '') {
            $merged['display_phone'] = $incoming['display_phone'];
        }
        if (($merged['phone'] ?? '') === '' && ($incoming['phone'] ?? '') !== '') {
            $merged['phone'] = $incoming['phone'];
        }
        if (($merged['line_label'] ?? '') === '' && ($incoming['line_label'] ?? '') !== '') {
            $merged['line_label'] = $incoming['line_label'];
        }
        if (($merged['instance'] ?? '') === '' && ($incoming['instance'] ?? '') !== '') {
            $merged['instance'] = $incoming['instance'];
        }

        $incomingMeta = $incoming['meta'] ?? [];
        $existingMeta = $existing['meta'] ?? [];
        if (is_array($incomingMeta) && is_array($existingMeta)) {
            $merged['meta'] = array_merge($existingMeta, $incomingMeta);
        }

        return $merged;
    }

    /**
     * Merge contact-level metadata with message-level metadata, preserving both sources.
     *
     * @param array<string,mixed> $contactMeta
     * @param array<string,mixed> $messageMeta
     * @return array<string,mixed>
     */
    private function mergeMetadata(array $contactMeta, array $messageMeta): array
    {
        if ($contactMeta === [] && $messageMeta === []) {
            return [];
        }

        return array_merge($contactMeta, $messageMeta);
    }

    /**
     * Upsert message by id to avoid duplicates per contact/day.
     *
     * @param array<int,array<string,mixed>> $messages
     * @param array<string,mixed> $message
     * @return array<int,array<string,mixed>>
     */
    private function upsertMessage(array $messages, array $message): array
    {
        $id = $message['id'] ?? null;
        if (is_string($id) && $id !== '') {
            foreach ($messages as $index => $existing) {
                if (($existing['id'] ?? null) === $id) {
                    $messages[$index] = $message;
                    return $messages;
                }
            }
        }

        $messages[] = $message;
        return $messages;
    }

    /** @return array<string,mixed> */
    private function loadContactRecord(string $contactFile): array
    {
        if (!is_file($contactFile)) {
            return [];
        }

        $content = file_get_contents($contactFile);
        if ($content === false || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function ensureDirectory(string $path): void
    {
        if (is_dir($path)) {
            return;
        }

        mkdir($path, 0775, true);
    }

    private function resolveIdentifier(array $payload): string
    {
        $candidates = [
            $payload['meta']['meta_message_id'] ?? null,
            $payload['meta']['message_id'] ?? null,
            $payload['meta']['external_id'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $this->slugify($candidate);
            }
        }

        return bin2hex(random_bytes(6));
    }

    private function guessExtension(?string $mime, ?string $type, ?string $fallback): string
    {
        $mime = $mime !== null ? strtolower(trim($mime)) : null;
        $type = $type !== null ? strtolower(trim($type)) : null;

        if ($mime === 'image/jpeg' || $mime === 'image/jpg') {
            return 'jpg';
        }
        if ($mime === 'image/png') {
            return 'png';
        }
        if ($mime === 'image/gif') {
            return 'gif';
        }
        if ($mime === 'audio/ogg' || $mime === 'audio/opus') {
            return 'ogg';
        }
        if ($mime === 'audio/mpeg' || $mime === 'audio/mp3') {
            return 'mp3';
        }
        if ($mime === 'video/mp4') {
            return 'mp4';
        }

        if ($type === 'image') {
            return 'jpg';
        }
        if ($type === 'audio') {
            return 'ogg';
        }
        if ($type === 'video') {
            return 'mp4';
        }

        if (is_string($fallback) && trim($fallback) !== '') {
            $extension = pathinfo($fallback, PATHINFO_EXTENSION);
            if ($extension !== '') {
                return strtolower($extension);
            }
        }

        return 'bin';
    }

    private function slugify(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9\-_]+/i', '-', $normalized) ?? 'entry';
        $normalized = trim($normalized, '-');
        return $normalized !== '' ? $normalized : 'entry';
    }
}
