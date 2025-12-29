<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Database\Connection;
use App\Repositories\Email\EmailCampaignBatchRepository;
use App\Repositories\Email\EmailCampaignRepository;
use App\Repositories\Email\EmailJobRepository;
use App\Repositories\Email\EmailSendRepository;
use RuntimeException;

final class CampaignSchedulerService
{
    private EmailCampaignRepository $campaigns;
    private EmailCampaignBatchRepository $batches;
    private EmailSendRepository $sends;
    private EmailJobRepository $jobs;
    private \PDO $pdo;

    public function __construct(
        ?EmailCampaignRepository $campaigns = null,
        ?EmailCampaignBatchRepository $batches = null,
        ?EmailSendRepository $sends = null,
        ?EmailJobRepository $jobs = null,
        ?\PDO $pdo = null
    ) {
        $this->campaigns = $campaigns ?? new EmailCampaignRepository();
        $this->batches = $batches ?? new EmailCampaignBatchRepository();
        $this->sends = $sends ?? new EmailSendRepository();
        $this->jobs = $jobs ?? new EmailJobRepository();
        $this->pdo = $pdo ?? Connection::instance();
    }

    /**
     * @param array{
     *     batch_size?: int,
     *     max_recipients?: int,
     *     min_batch_size?: int
     * } $options
     * @return array{batches: int, recipients: int, batch_ids: int[]}
     */
    public function schedule(int $emailCampaignId, array $options = []): array
    {
        $campaign = $this->campaigns->find($emailCampaignId);
        if ($campaign === null) {
            throw new RuntimeException('Campanha não encontrada.');
        }

        $status = (string)($campaign['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'paused', 'ready'], true)) {
            throw new RuntimeException(sprintf('Campanha não pode ser agendada a partir do status %s.', $status));
        }

        $batchSize = max(100, (int)($options['batch_size'] ?? 1000));
        $minBatchSize = max(50, (int)($options['min_batch_size'] ?? max(50, (int)floor($batchSize / 2))));
        $maxRecipients = isset($options['max_recipients']) ? max(1, (int)$options['max_recipients']) : null;

        $recipientsIterator = $this->recipientStream($campaign);
        $currentChunk = [];
        $totalRecipients = 0;
        $batchIds = [];

        foreach ($recipientsIterator as $recipient) {
            if ($maxRecipients !== null && $totalRecipients >= $maxRecipients) {
                break;
            }

            if (!filter_var($recipient['email'], FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $currentChunk[] = $recipient;
            $totalRecipients++;

            if (count($currentChunk) >= $batchSize) {
                $batchIds[] = $this->persistBatch($campaign, $currentChunk);
                $currentChunk = [];
            }
        }

        if ($currentChunk !== [] && (count($currentChunk) >= $minBatchSize || $batchIds === [])) {
            $batchIds[] = $this->persistBatch($campaign, $currentChunk);
        }

        if ($batchIds === []) {
            throw new RuntimeException('Nenhum destinatário elegível foi encontrado para esta campanha.');
        }

        $this->campaigns->update($emailCampaignId, ['status' => 'scheduled']);

        return [
            'batches' => count($batchIds),
            'recipients' => $totalRecipients,
            'batch_ids' => $batchIds,
        ];
    }

    /**
     * @param array<string, mixed> $campaign
     * @param list<array<string, mixed>> $recipients
     */
    private function persistBatch(array $campaign, array $recipients): int
    {
        $timestamp = time();
        $batchId = $this->batches->insert([
            'email_campaign_id' => (int)$campaign['id'],
            'status' => 'pending',
            'total_recipients' => count($recipients),
            'processed_count' => 0,
            'failed_count' => 0,
            'scheduled_for' => $campaign['scheduled_for'] ?? null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $rows = [];
        foreach ($recipients as $recipient) {
            $rows[] = [
                'email_campaign_id' => (int)$campaign['id'],
                'batch_id' => $batchId,
                'account_id' => $campaign['from_account_id'] ?? null,
                'contact_id' => $recipient['contact_id'] ?? null,
                'client_id' => $recipient['client_id'] ?? null,
                'rfb_prospect_id' => $recipient['rfb_prospect_id'] ?? null,
                'target_email' => $recipient['email'],
                'target_name' => $recipient['name'],
                'status' => 'pending',
                'attempts' => 0,
                'last_error' => null,
                'scheduled_at' => $campaign['scheduled_for'] ?? null,
                'metadata' => $this->encodeJson([
                    'source' => $recipient['source'],
                    'source_id' => $recipient['source_id'],
                ]),
            ];
        }

        $this->sends->bulkInsert($rows);
        $this->jobs->enqueue('email.campaign.dispatch', ['batch_id' => $batchId], ['priority' => 10]);

        return $batchId;
    }

    /**
     * @param array<string, mixed> $campaign
     * @return iterable<int, array{email: string, name: string, contact_id?: int|null, client_id?: int|null, rfb_prospect_id?: int|null, source: string, source_id: int|string|null}>
     */
    private function recipientStream(array $campaign): iterable
    {
        $sourceType = $campaign['source_type'] ?? 'list';
        if ($sourceType === 'segment' && !empty($campaign['segment_id'])) {
            yield from $this->streamSegmentRecipients((int)$campaign['segment_id']);
            return;
        }

        if ($sourceType === 'rfb' && !empty($campaign['rfb_filter'])) {
            yield from $this->streamRfbRecipients((string)$campaign['rfb_filter']);
            return;
        }

        if (!empty($campaign['list_id'])) {
            yield from $this->streamListRecipients((int)$campaign['list_id']);
            return;
        }

        throw new RuntimeException('Campanha sem origem de destinatários configurada.');
    }

    private function streamListRecipients(int $listId, int $pageSize = 1000): iterable
    {
        $offset = 0;
        while (true) {
            $stmt = $this->pdo->prepare(
                'SELECT c.id AS contact_id, c.first_name, c.last_name, c.email
                 FROM audience_list_contacts lc
                 INNER JOIN marketing_contacts c ON c.id = lc.contact_id
                 WHERE lc.list_id = :list_id
                   AND lc.subscription_status = "subscribed"
                   AND c.status = "active"
                 ORDER BY c.id ASC
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':list_id', $listId, \PDO::PARAM_INT);
            $stmt->bindValue(':limit', $pageSize, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $email = trim((string)($row['email'] ?? ''));
                if ($email === '') {
                    continue;
                }

                $name = trim(implode(' ', array_filter([
                    $row['first_name'] ?? '',
                    $row['last_name'] ?? '',
                ])));
                yield [
                    'email' => strtolower($email),
                    'name' => $name !== '' ? $name : $email,
                    'contact_id' => (int)$row['contact_id'],
                    'client_id' => null,
                    'rfb_prospect_id' => null,
                    'source' => 'list',
                    'source_id' => $listId,
                ];
            }

            $offset += $pageSize;
        }
    }

    private function streamSegmentRecipients(int $segmentId, int $pageSize = 1000): iterable
    {
        $offset = 0;
        while (true) {
            $stmt = $this->pdo->prepare(
                'SELECT c.id AS contact_id, c.first_name, c.last_name, c.email
                 FROM marketing_segment_contacts sc
                 INNER JOIN marketing_contacts c ON c.id = sc.contact_id
                 WHERE sc.segment_id = :segment_id
                   AND c.status = "active"
                 ORDER BY c.id ASC
                 LIMIT :limit OFFSET :offset'
            );
            $stmt->bindValue(':segment_id', $segmentId, \PDO::PARAM_INT);
            $stmt->bindValue(':limit', $pageSize, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $email = trim((string)($row['email'] ?? ''));
                if ($email === '') {
                    continue;
                }

                $name = trim(implode(' ', array_filter([
                    $row['first_name'] ?? '',
                    $row['last_name'] ?? '',
                ])));
                yield [
                    'email' => strtolower($email),
                    'name' => $name !== '' ? $name : $email,
                    'contact_id' => (int)$row['contact_id'],
                    'client_id' => null,
                    'rfb_prospect_id' => null,
                    'source' => 'segment',
                    'source_id' => $segmentId,
                ];
            }

            $offset += $pageSize;
        }
    }

    private function streamRfbRecipients(string $filter, int $pageSize = 1000): iterable
    {
        $offset = 0;
        $filterSql = trim($filter) !== '' ? ' AND rp.filter_key = :filter' : '';

        while (true) {
            $sql = 'SELECT rp.id AS rfb_prospect_id, rp.email, rp.company_name
                    FROM rfb_prospects rp
                    WHERE rp.email IS NOT NULL AND rp.email != ""' . $filterSql . '
                    ORDER BY rp.id ASC
                    LIMIT :limit OFFSET :offset';

            $stmt = $this->pdo->prepare($sql);
            if ($filterSql !== '') {
                $stmt->bindValue(':filter', $filter);
            }
            $stmt->bindValue(':limit', $pageSize, \PDO::PARAM_INT);
            $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
            if (!$rows) {
                break;
            }

            foreach ($rows as $row) {
                $email = trim((string)($row['email'] ?? ''));
                if ($email === '') {
                    continue;
                }

                $name = trim((string)($row['company_name'] ?? ''));
                yield [
                    'email' => strtolower($email),
                    'name' => $name !== '' ? $name : $email,
                    'contact_id' => null,
                    'client_id' => null,
                    'rfb_prospect_id' => (int)$row['rfb_prospect_id'],
                    'source' => 'rfb',
                    'source_id' => $filter,
                ];
            }

            $offset += $pageSize;
        }
    }

    private function encodeJson(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
