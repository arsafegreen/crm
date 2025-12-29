<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Repositories\Marketing\MarketingContactRepository;
use App\Repositories\Marketing\AudienceListRepository;
use App\Services\AlertService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class MarketingListSweepController
{
    private const STATUS_FILE = __DIR__ . '/../../storage/var/sweep_status.json';
    private const HISTORY_FILE = __DIR__ . '/../../storage/var/sweep_history.jsonl';
    private const DEFAULT_BATCH_SIZE = 200;

    public function start(Request $request): Response
    {
        $listId = (int)$request->get('list_id', 0);
        $resume = (bool)$request->get('resume', false);
        $useExternal = (bool)$request->get('external_validation', false);
        $batchSize = $this->sanitizeBatchSize((int)$request->get('batch_size', self::DEFAULT_BATCH_SIZE));

        $current = $this->loadStatus();
        if ($resume && ($current['status'] ?? '') === 'paused') {
            $current['status'] = 'running';
            $current['updated_at'] = time();
            $current['message'] = 'Execução retomada';
            if ($useExternal) {
                $current['use_external_validation'] = true;
            }
            $this->saveStatus($current);
            return $this->json(['ok' => true, 'resumed' => true, 'status' => $current]);
        }

        $listRepo = new AudienceListRepository();
        $lists = $listRepo->all();
        if ($listId > 0) {
            $lists = array_values(array_filter($lists, static fn(array $row): bool => (int)($row['id'] ?? 0) === $listId));
        }

        $listIds = array_values(array_map('intval', array_column($lists, 'id')));
        if ($listIds === []) {
            $status = [
                'status' => 'stopped',
                'message' => 'Nenhuma lista encontrada para varrer',
                'total' => 0,
                'checked' => 0,
                'bounces' => 0,
                'updated_at' => time(),
            ];
            $this->saveStatus($status);
            return $this->json(['ok' => false, 'error' => 'Nenhuma lista encontrada', 'status' => $status], 400);
        }

        $listTotals = [];
        $grandTotal = 0;
        foreach ($listIds as $id) {
            $count = $listRepo->countContacts($id);
            $listTotals[$id] = $count;
            $grandTotal += $count;
        }

        $status = [
            'status' => 'running',
            'started_at' => time(),
            'updated_at' => time(),
            'message' => 'Varredura iniciada',
            'list_ids' => $listIds,
            'list_totals' => $listTotals,
            'current_list_index' => 0,
            'offset' => 0,
            'batch_size' => $batchSize,
            'checked' => 0,
            'bounces' => 0,
            'total' => $grandTotal,
            'use_external_validation' => $useExternal,
            'mx_cache' => [],
            'history_logged' => false,
        ];

        $this->saveStatus($status);
        return $this->json(['ok' => true, 'status' => $status]);
    }

    public function stop(Request $request): Response
    {
        $status = $this->loadStatus();
        $status['status'] = 'paused';
        $status['paused_at'] = time();
        $status['updated_at'] = time();
        $status['message'] = 'Pausada manualmente';
        $this->saveStatus($status);

        return $this->json(['ok' => true, 'status' => $status]);
    }

    public function status(Request $request): Response
    {
        return $this->json($this->loadStatus());
    }

    public function suppressions(Request $request): Response
    {
        $repo = new MarketingContactRepository();
        $query = (string)$request->get('q', '');
        $page = max(1, (int)$request->get('page', 1));
        $limit = max(1, min(100, (int)$request->get('limit', 50)));
        $offset = ($page - 1) * $limit;

        $result = $repo->listSuppressed($query, $limit, $offset);

        return $this->json($result);
    }

    public function unsuppress(Request $request): Response
    {
        $id = (int)$request->get('id', 0);
        if ($id <= 0) {
            return $this->json(['ok' => false, 'error' => 'ID inválido'], 400);
        }
        $repo = new MarketingContactRepository();
        $repo->restoreSuppression($id);
        return $this->json(['ok' => true]);
    }

    public function exportSuppressions(Request $request): Response
    {
        $repo = new MarketingContactRepository();
        $result = $repo->listSuppressed('', 5000, 0);
        $lines = ["email,suppression_reason,updated_at"];
        foreach ($result['items'] as $item) {
            $lines[] = sprintf('"%s","%s","%s"',
                $item['email'],
                str_replace('"', '""', (string)($item['suppression_reason'] ?? '')),
                $item['updated_at'] ? date('c', $item['updated_at']) : ''
            );
        }
        $csv = implode("\r\n", $lines);
        return new Response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="suppressions.csv"'
        ]);
    }

    public function importSuppressions(Request $request): Response
    {
        $file = $request->files->get('file');
        if ($file === null) {
            return $this->json(['ok' => false, 'error' => 'Arquivo não enviado'], 400);
        }
        $path = $file->getRealPath();
        if (!$path || !is_file($path)) {
            return $this->json(['ok' => false, 'error' => 'Arquivo inválido'], 400);
        }

        $content = file_get_contents($path);
        if ($content === false) {
            return $this->json(['ok' => false, 'error' => 'Falha ao ler arquivo'], 400);
        }

        $tokens = preg_split('/[\s,;]+/u', $content, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $repo = new MarketingContactRepository();
        $seen = [];
        $suppressed = 0;
        foreach ($tokens as $token) {
            $email = strtolower(trim((string)$token));
            if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }
            if (isset($seen[$email])) {
                continue;
            }
            $seen[$email] = true;

            $existing = $repo->findByEmail($email);
            if ($existing !== null) {
                $repo->markOptOut((int)$existing['id'], 'imported_suppression');
                $suppressed++;
                continue;
            }
            $repo->create([
                'email' => $email,
                'status' => 'inactive',
                'consent_status' => 'opted_out',
                'bounce_count' => 3,
                'suppression_reason' => 'imported_suppression',
            ]);
            $suppressed++;
        }

        return $this->json(['ok' => true, 'suppressed' => $suppressed]);
    }

    public function processBatch(Request $request): Response
    {
        $status = $this->loadStatus();
        if (($status['status'] ?? 'stopped') !== 'running') {
            return $this->json(['ok' => false, 'message' => 'Nenhuma varredura em execução', 'status' => $status], 400);
        }

        $contactRepo = new MarketingContactRepository();
        $listRepo = new AudienceListRepository();

        $listIds = $status['list_ids'] ?? [];
        $currentIndex = (int)($status['current_list_index'] ?? 0);
        $offset = (int)($status['offset'] ?? 0);
        $batchSize = $this->sanitizeBatchSize((int)($status['batch_size'] ?? self::DEFAULT_BATCH_SIZE));
        $useExternal = (bool)($status['use_external_validation'] ?? false);
        $mxCache = is_array($status['mx_cache'] ?? null) ? $status['mx_cache'] : [];

        $checked = (int)($status['checked'] ?? 0);
        $bounces = (int)($status['bounces'] ?? 0);
        $processed = 0;

        while ($processed < $batchSize) {
            if (!isset($listIds[$currentIndex])) {
                return $this->finishSweep($status, $checked, $bounces, $mxCache);
            }

            $listId = (int)$listIds[$currentIndex];
            $contacts = $listRepo->contacts($listId, null, $batchSize, $offset);
            if ($contacts === []) {
                $currentIndex++;
                $offset = 0;
                continue;
            }

            foreach ($contacts as $contact) {
                if ($processed >= $batchSize) {
                    break;
                }

                $processed++;
                $checked++;
                $email = strtolower(trim((string)($contact['email'] ?? '')));
                $contactId = (int)($contact['id'] ?? 0);

                $isAlreadySuppressed = (($contact['status'] ?? '') === 'inactive')
                    && ((($contact['consent_status'] ?? '') === 'opted_out')
                        || ($contact['suppression_reason'] ?? null) !== null
                        || (int)($contact['bounce_count'] ?? 0) >= 3);

                if ($isAlreadySuppressed) {
                    continue;
                }

                $markBounce = function (string $reason) use ($contactRepo, $listRepo, $listId, $contactId, &$bounces): void {
                    $bounces++;
                    if ($contactId > 0) {
                        $contactRepo->markOptOut($contactId, $reason);
                        $listRepo->unsubscribe($listId, $contactId, $reason);
                    }
                };

                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    $markBounce('invalid_email');
                    continue;
                }

                if ($useExternal && !$this->hasValidMx($email, $mxCache)) {
                    $markBounce('invalid_mx');
                    continue;
                }
            }

            $offset += count($contacts);
            if (count($contacts) < $batchSize) {
                $currentIndex++;
                $offset = 0;
            }
        }

        $status['current_list_index'] = $currentIndex;
        $status['offset'] = $offset;
        $status['checked'] = $checked;
        $status['bounces'] = $bounces;
        $status['batch_size'] = $batchSize;
        $status['mx_cache'] = $this->trimCache($mxCache);
        $status['updated_at'] = time();
        $status['message'] = 'Processando';
        $this->saveStatus($status);

        return $this->json(['ok' => true, 'processed' => $processed, 'status' => $status]);
    }

    public function history(Request $request): Response
    {
        $file = self::HISTORY_FILE;
        if (!is_file($file)) {
            return $this->json([]);
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lines = array_slice(array_reverse($lines), 0, 100);
        $items = array_values(array_filter(array_map(static function ($line) {
            $decoded = json_decode($line, true);
            return is_array($decoded) ? $decoded : null;
        }, $lines)));
        return $this->json($items);
    }

    private function finishSweep(array $status, int $checked, int $bounces, array $mxCache): Response
    {
        $entry = [
            'checked' => $checked,
            'total' => $status['total'] ?? 0,
            'bounces' => $bounces,
            'list_id' => $status['list_ids'][0] ?? null,
            'ts' => time(),
        ];

        $status['status'] = 'stopped';
        $status['finished_at'] = time();
        $status['updated_at'] = time();
        $status['checked'] = $checked;
        $status['bounces'] = $bounces;
        $status['message'] = 'Concluído';
        $status['mx_cache'] = $this->trimCache($mxCache);

        $this->saveStatus($status);

        if (empty($status['history_logged'])) {
            $this->appendHistory($entry);
            $this->notifyIfHighBounce($entry);
            $status['history_logged'] = true;
            $this->saveStatus($status);
        }

        AlertService::push('bounce.sweep', 'Varredura de bounces executada', $entry);

        return $this->json(['ok' => true, 'done' => true, 'status' => $status]);
    }

    private function loadStatus(): array
    {
        $file = self::STATUS_FILE;
        if (!is_file($file)) {
            return ['status' => 'stopped'];
        }
        $data = json_decode((string)file_get_contents($file), true);
        return is_array($data) ? $data : ['status' => 'stopped'];
    }

    private function saveStatus(array $data): void
    {
        $dir = dirname(self::STATUS_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        file_put_contents(self::STATUS_FILE, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function appendHistory(array $entry): void
    {
        $dir = dirname(self::HISTORY_FILE);
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true);
        }
        @file_put_contents(self::HISTORY_FILE, json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n", FILE_APPEND);
    }

    private function notifyIfHighBounce(array $entry): void
    {
        $total = max(1, (int)($entry['total'] ?? 0));
        $bounces = (int)($entry['bounces'] ?? 0);
        $rate = $bounces / $total;
        if ($bounces >= 20 || $rate >= 0.1) {
            AlertService::push('bounce.alert', 'Alerta: bounces elevados na varredura', [
                'total' => $total,
                'bounces' => $bounces,
                'rate' => round($rate, 4),
                'list_id' => $entry['list_id'] ?? null,
                'ts' => time(),
            ]);
        }
    }

    private function hasValidMx(string $email, array &$cache): bool
    {
        $at = strpos($email, '@');
        if ($at === false) {
            return false;
        }
        $domain = substr($email, $at + 1);
        if ($domain === '') {
            return false;
        }

        if (isset($cache[$domain])) {
            return $cache[$domain];
        }

        $mxRecords = function_exists('dns_get_record') ? @dns_get_record($domain, DNS_MX) : null;
        $hasMx = ($mxRecords !== false && $mxRecords !== null && $mxRecords !== []);
        if (!$hasMx) {
            $hasMx = @checkdnsrr($domain, 'MX');
        }

        $cache[$domain] = $hasMx;
        return $hasMx;
    }

    private function trimCache(array $cache): array
    {
        $max = 200;
        if (count($cache) <= $max) {
            return $cache;
        }

        return array_slice($cache, -$max, $max, true);
    }

    private function sanitizeBatchSize(int $value): int
    {
        if ($value <= 0) {
            return self::DEFAULT_BATCH_SIZE;
        }
        return max(20, min(500, $value));
    }

    private function json($payload, int $status = 200): Response
    {
        return new Response(json_encode($payload), $status, ['Content-Type' => 'application/json']);
    }
}
