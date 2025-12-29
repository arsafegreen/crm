<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Repositories\CertificateRepository;
use App\Repositories\ClientRepository;
use App\Repositories\ClientStageHistoryRepository;
use App\Repositories\PartnerRepository;
use App\Repositories\PipelineRepository;
use App\Repositories\RfbProspectRepository;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;

final class ClientImportService
{
    private ClientRepository $clients;
    private CertificateRepository $certificates;
    private PartnerRepository $partners;
    private PipelineRepository $pipelines;
    private ClientStageHistoryRepository $history;
    private RfbProspectRepository $rfb;

    private ?int $defaultStageId;
    private ?int $lostStageId;
    private bool $rejectOlderCertificates = false;
    /** @var int[] */
    private array $touchedClients = [];
    /** @var array<int,bool> */
    private array $seenProtocols = [];

    public function __construct()
    {
        $this->clients = new ClientRepository();
        $this->certificates = new CertificateRepository();
        $this->partners = new PartnerRepository();
        $this->pipelines = new PipelineRepository();
        $this->history = new ClientStageHistoryRepository();
        $this->rfb = new RfbProspectRepository();

        $this->defaultStageId = $this->pipelines->findStageByName('Pós-venda')['id'] ?? null;
        $this->lostStageId = $this->pipelines->findStageByName('Perdido')['id'] ?? null;
    }

    public function import(string $filePath, array $options = []): array
    {
        $path = $this->resolvePath($filePath);
        if (!file_exists($path)) {
            throw new RuntimeException("Arquivo não encontrado: {$path}");
        }

        $this->rejectOlderCertificates = (bool)($options['reject_older_certificates'] ?? false);

        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, true, true);

        if ($rows === []) {
            throw new RuntimeException('Planilha vazia.');
        }

        $headers = [];
        $stats = [
            'processed' => 0,
            'skipped' => 0,
            'created_clients' => 0,
            'updated_clients' => 0,
            'created_certificates' => 0,
            'updated_certificates' => 0,
            'skipped_older_certificates' => 0,
            'skipped_duplicate_protocols' => 0,
        ];

        $this->touchedClients = [];
        $this->seenProtocols = [];

        foreach ($rows as $index => $row) {
            if ($index === 1) {
                foreach ($row as $columnKey => $value) {
                    $headers[$columnKey] = $value !== null ? trim((string)$value) : '';
                }
                continue;
            }

            $assoc = [];
            foreach ($row as $columnKey => $value) {
                $header = $headers[$columnKey] ?? $columnKey;
                $assoc[$header] = $value;
            }

            if ($this->processRow($assoc, $stats)) {
                $stats['processed']++;
            } else {
                $stats['skipped']++;
            }
        }

        $this->synchronizeClients($this->touchedClients);
        $this->removeFromRfbBase($this->touchedClients);

        return $stats;
    }

    private function removeFromRfbBase(array $clientIds): void
    {
        if ($clientIds === []) {
            return;
        }

        $documents = $this->clients->documentsByIds($clientIds);
        if ($documents === []) {
            return;
        }

        $cnpjs = array_values(array_filter($documents, static fn(string $document): bool => strlen($document) === 14));
        if ($cnpjs === []) {
            return;
        }

        $this->rfb->deleteByCnpjs($cnpjs);
    }

    private function processRow(array $row, array &$stats): bool
    {
        $protocol = trim((string)($row['Protocolo'] ?? ''));
        $document = digits_only($row['Documento'] ?? '');
        $protocolNumber = $this->protocolNumber($protocol);

        if ($protocol === '' || $document === '' || $protocolNumber === 0) {
            return false;
        }

        if (isset($this->seenProtocols[$protocolNumber])) {
            $stats['skipped_duplicate_protocols']++;
            return false;
        }
        $this->seenProtocols[$protocolNumber] = true;

        $parsed = $this->parseRow($row);
        $this->syncPartners($parsed['partner_accountant'], $parsed['partner_accountant_plus']);

        $client = $this->clients->findByDocument($document);
        $clientId = $client['id'] ?? null;
        $isLost = $client !== null && $this->lostStageId !== null && (int)($client['pipeline_stage_id'] ?? 0) === $this->lostStageId;

        $certificate = $this->certificates->findByProtocol($protocol);
        $certificatePayload = $this->buildCertificatePayload((int)($clientId ?? 0), $row, $parsed);

        if ($this->rejectOlderCertificates && $client !== null) {
            $currentProtocol = (string)($client['last_protocol'] ?? '');
            if ($currentProtocol !== '' && !$this->isNewerOrEqualProtocol($parsed['protocol'], $currentProtocol)) {
                $stats['skipped_older_certificates']++;
                return false;
            }
        }

        if ($client === null) {
            $clientData = $this->buildClientPayloadForInsert($document, $row, $parsed);
            $clientId = $this->clients->insert($clientData);
            $this->history->record($clientId, null, $clientData['pipeline_stage_id'] ?? null, 'Importação: cliente criado');
            $client = $this->clients->find($clientId);
            $stats['created_clients']++;
        } else {
            $stageChange = null;
            $updateData = $this->buildClientPayloadForUpdate($client, $parsed, $row, $isLost, $stageChange);
            if ($updateData !== []) {
                $this->clients->update((int)$client['id'], $updateData);
                $client = $this->clients->find((int)$client['id']);
                $stats['updated_clients']++;
            }
            if ($stageChange !== null) {
                $this->history->record((int)$client['id'], $stageChange['from'], $stageChange['to'], 'Importação: atualização de estágio');
            }
        }

        if ($clientId === null) {
            return false;
        }

        $this->touchedClients[] = (int)$clientId;

        $certificatePayload['client_id'] = (int)$clientId;

        if ($certificate === null) {
            $this->certificates->insert($certificatePayload);
            $stats['created_certificates']++;
        } else {
            $this->certificates->update((int)$certificate['id'], $certificatePayload);
            $stats['updated_certificates']++;
        }

        return true;
    }

    private function buildClientPayloadForInsert(string $document, array $row, array $parsed): array
    {
        $status = $parsed['client_status'];
        $data = [
            'document' => $document,
            'name' => strtoupper(trim((string)($row['Nome'] ?? 'Sem Nome'))),
            'titular_name' => trim((string)($row['Nome do Titular'] ?? '')) ?: null,
            'titular_document' => $parsed['titular_document'],
            'titular_birthdate' => $parsed['titular_birthdate'],
            'email' => $parsed['email'],
            'phone' => $parsed['phone'],
            'whatsapp' => $parsed['phone'],
            'status' => $status,
            'status_changed_at' => now(),
            'last_protocol' => $parsed['protocol'],
            'last_certificate_expires_at' => $parsed['end_at'],
            'next_follow_up_at' => $parsed['next_follow_up_at'],
            'partner_accountant' => $parsed['partner_accountant'],
            'partner_accountant_plus' => $parsed['partner_accountant_plus'],
            'pipeline_stage_id' => $this->defaultStageId,
            'notes' => null,
            'document_status' => 'active',
            'is_off' => 0,
            'last_avp_name' => $parsed['avp_name'],
        ];

        if ($this->defaultStageId === null) {
            unset($data['pipeline_stage_id']);
        }

        return $data;
    }

    private function buildClientPayloadForUpdate(array $client, array $parsed, array $row, bool $isLost, ?array &$stageChange): array
    {
        $stageChange = null;
        $update = [];

        $incomingProtocolNumber = $parsed['protocol_number'];
        $currentProtocolNumber = $this->protocolNumber((string)($client['last_protocol'] ?? ''));
        $canApplyNewerProtocol = $incomingProtocolNumber >= $currentProtocolNumber;

        if ($canApplyNewerProtocol) {
            $name = strtoupper(trim((string)($row['Nome'] ?? '')));
            if ($name !== '' && $name !== ($client['name'] ?? '')) {
                $update['name'] = $name;
            }

            foreach ([
                'titular_name' => trim((string)($row['Nome do Titular'] ?? '')) ?: null,
                'titular_document' => $parsed['titular_document'],
                'titular_birthdate' => $parsed['titular_birthdate'],
                'email' => $parsed['email'],
                'phone' => $parsed['phone'],
                'whatsapp' => $parsed['phone'],
                'partner_accountant' => $parsed['partner_accountant'],
                'partner_accountant_plus' => $parsed['partner_accountant_plus'],
            ] as $field => $value) {
                if ($value !== null && $value !== '' && $value !== ($client[$field] ?? null)) {
                    $update[$field] = $value;
                }
            }
        }

        $latestExpire = $client['last_certificate_expires_at'] ?? null;
        $newExpire = $parsed['end_at'];
        $isNewerProtocol = $canApplyNewerProtocol;

        if ($newExpire !== null && $isNewerProtocol) {
            $update['last_certificate_expires_at'] = $newExpire;
            $update['last_protocol'] = $parsed['protocol'];
            $update['next_follow_up_at'] = $parsed['next_follow_up_at'];
            if (!$parsed['is_revoked']) {
                $update['is_off'] = 0;
            }
        }

        $incomingAvp = $parsed['avp_name'] ?? null;
        $currentAvp = $client['last_avp_name'] ?? null;
        if ($incomingAvp !== null && $incomingAvp !== '') {
            $shouldUpdateAvp = $currentAvp === null || $currentAvp === '';
            if ($isNewerProtocol) {
                $shouldUpdateAvp = true;
            }

            if ($shouldUpdateAvp && $incomingAvp !== $currentAvp) {
                $update['last_avp_name'] = $incomingAvp;
            }
        }

        $currentStatus = $client['status'] ?? 'prospect';
        $newStatus = $isLost ? 'lost' : $parsed['client_status'];
        $shouldUpdateStatus = false;

        if ($canApplyNewerProtocol) {
            if ($isLost) {
                $shouldUpdateStatus = $newStatus !== $currentStatus;
            } elseif ($newExpire !== null) {
                $shouldUpdateStatus = $newStatus !== $currentStatus;
            } else {
                $shouldUpdateStatus = $latestExpire === null && $newStatus !== $currentStatus;
            }
        }

        if ($shouldUpdateStatus) {
            $update['status'] = $newStatus;
            $update['status_changed_at'] = now();
        }

        if (!$isLost && $this->defaultStageId !== null) {
            $currentStageId = $client['pipeline_stage_id'] ?? null;
            if ((int)$currentStageId !== $this->defaultStageId) {
                $update['pipeline_stage_id'] = $this->defaultStageId;
                $stageChange = [
                    'from' => $currentStageId !== null ? (int)$currentStageId : null,
                    'to' => $this->defaultStageId,
                ];
            }
        }

        return $update;
    }

    private function buildCertificatePayload(int $clientId, array $row, array $parsed): array
    {
        $status = $parsed['certificate_status'];
        $payload = [
            'client_id' => $clientId,
            'protocol' => $parsed['protocol'],
            'product_name' => trim((string)($row['Produto'] ?? '')) ?: null,
            'product_description' => trim((string)($row['Descrição do Produto'] ?? '')) ?: null,
            'validity_label' => trim((string)($row['Validade'] ?? '')) ?: null,
            'media_description' => trim((string)($row['Descrição Produto Mídia'] ?? '')) ?: null,
            'serial_number' => trim((string)($row['Numero de Série'] ?? '')) ?: null,
            'status' => $status,
            'is_revoked' => $parsed['is_revoked'] ? 1 : 0,
            'revocation_reason' => $parsed['revocation_reason'],
            'start_at' => $parsed['start_at'],
            'end_at' => $parsed['end_at'],
            'avp_at' => $parsed['avp_at'],
            'avp_name' => trim((string)($row['Nome do AVP'] ?? '')) ?: null,
            'avp_cpf' => digits_only($row['CPF do AVP'] ?? ''),
            'aci_name' => trim((string)($row['Nome do ACI'] ?? '')) ?: null,
            'aci_cpf' => digits_only($row['CPF do ACI'] ?? ''),
            'location_name' => trim((string)($row['Nome do Local de Atendimento'] ?? '')) ?: null,
            'location_alias' => trim((string)($row['Apelido do Local de Atendimento'] ?? '')) ?: null,
            'city_name' => trim((string)($row['Nome da Cidade'] ?? '')) ?: null,
            'emission_type' => trim((string)($row['Tipo de Emissão Realizada'] ?? '')) ?: null,
            'requested_type' => trim((string)($row['Tipo de Emissão Solicitada'] ?? '')) ?: null,
            'partner_name' => trim((string)($row['Nome do Parceiro'] ?? '')) ?: null,
            'partner_accountant' => $parsed['partner_accountant'],
            'partner_accountant_plus' => $parsed['partner_accountant_plus'],
            'renewal_protocol' => trim((string)($row['Protocolo Renovação'] ?? '')) ?: null,
            'status_raw' => trim((string)($row['Status do Certificado'] ?? '')) ?: null,
            'source_payload' => json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];

        return $payload;
    }

    private function parseRow(array $row): array
    {
        $startAt = $this->toTimestamp($row['Data Inicio Validade'] ?? null);
        $endAt = $this->toTimestamp($row['Data Fim Validade'] ?? null);
        $revokedAt = $this->toTimestamp($row['Data de Revogação'] ?? null);
        $avpAt = $this->toTimestamp($row['Data AVP'] ?? null);
        if ($avpAt === null && $startAt !== null) {
            $avpAt = $startAt;
        }

        $statusRaw = trim((string)($row['Status do Certificado'] ?? ''));
        $statusSlug = $statusRaw === '' ? 'desconhecido' : strtolower(str_replace(' ', '_', $statusRaw));

        $normalizedStatus = $this->normalizeStatusLabel($statusRaw);
        $isRevoked = $revokedAt !== null;
        if (!$isRevoked && $normalizedStatus !== '') {
            $containsRevog = str_contains($normalizedStatus, 'revog');
            $containsNotRevoked = str_contains($normalizedStatus, 'nao revogado');
            if ($containsRevog && !$containsNotRevoked) {
                $isRevoked = true;
            }
        }

        $endAtForStatus = $isRevoked ? null : $endAt;
        $clientStatus = $this->resolveClientStatus($endAtForStatus, $isRevoked);
        $nextFollowUp = $this->nextFollowUp($endAtForStatus);

        return [
            'protocol' => trim((string)($row['Protocolo'] ?? '')),
            'protocol_number' => $this->protocolNumber((string)($row['Protocolo'] ?? '')),
            'start_at' => $startAt,
            'end_at' => $endAt,
            'is_revoked' => $isRevoked,
            'revocation_reason' => trim((string)($row['Descrição Revogação'] ?? '')) ?: null,
            'certificate_status' => $statusSlug,
            'client_status' => $clientStatus,
            'next_follow_up_at' => $nextFollowUp,
            'titular_document' => digits_only($row['Documento do Titular'] ?? ''),
            'titular_birthdate' => $this->toTimestamp($row['Data de Nascimento do Titular'] ?? null, true),
            'email' => $this->sanitizeEmail($row['E-mail do Titular'] ?? null),
            'phone' => $this->sanitizePhone($row['Telefone do Titular'] ?? null),
            'partner_accountant' => $this->sanitizeName($row['Nome Contador Parceiro'] ?? null),
            'partner_accountant_plus' => $this->sanitizeName($row['Nome Contador Parceiro Mais'] ?? null),
            'avp_name' => $this->sanitizeName($row['Nome do AVP'] ?? null),
            'avp_cpf' => digits_only($row['CPF do AVP'] ?? ''),
            'avp_at' => $avpAt,
        ];
    }

    public function refreshClients(array $clientIds): void
    {
        $this->synchronizeClients($clientIds);
    }

    private function synchronizeClients(array $clientIds): void
    {
        if ($clientIds === []) {
            return;
        }

        $clientIds = array_values(array_unique(array_map('intval', $clientIds)));

        foreach ($clientIds as $clientId) {
            if ($clientId <= 0) {
                continue;
            }

            $client = $this->clients->find($clientId);
            if ($client === null) {
                continue;
            }

            $latestCertificate = $this->certificates->latestForClient($clientId);
            $updates = [];

            if ($latestCertificate === null) {
                if (($client['status'] ?? '') !== 'prospect') {
                    $updates['status'] = 'prospect';
                    $updates['status_changed_at'] = now();
                }
                if (($client['last_certificate_expires_at'] ?? null) !== null) {
                    $updates['last_certificate_expires_at'] = null;
                }
                if (($client['last_protocol'] ?? null) !== null) {
                    $updates['last_protocol'] = null;
                }
                if (($client['next_follow_up_at'] ?? null) !== null) {
                    $updates['next_follow_up_at'] = null;
                }
                if ($updates !== []) {
                    $this->clients->update($clientId, $updates);
                }
                continue;
            }

            $endAt = $latestCertificate['end_at'] !== null ? (int)$latestCertificate['end_at'] : null;
            $isRevoked = (int)($latestCertificate['is_revoked'] ?? 0) === 1;
            $status = $this->resolveClientStatus($isRevoked ? null : $endAt, $isRevoked);
            $documentStatus = $isRevoked ? 'dropped' : 'active';
            $nextFollowUp = $this->nextFollowUp($isRevoked ? null : $endAt);

            if (($client['status'] ?? '') !== $status) {
                $updates['status'] = $status;
                $updates['status_changed_at'] = now();
            }
            if (($client['document_status'] ?? 'active') !== $documentStatus) {
                $updates['document_status'] = $documentStatus;
            }
            if (($client['last_protocol'] ?? null) !== ($latestCertificate['protocol'] ?? null)) {
                $updates['last_protocol'] = $latestCertificate['protocol'];
            }
            if (($client['last_certificate_expires_at'] ?? null) !== $endAt) {
                $updates['last_certificate_expires_at'] = $endAt;
            }
            if (($client['next_follow_up_at'] ?? null) !== $nextFollowUp) {
                $updates['next_follow_up_at'] = $nextFollowUp;
            }
            $latestAvp = trim((string)($latestCertificate['avp_name'] ?? ''));
            $currentAvp = trim((string)($client['last_avp_name'] ?? ''));
            if ($latestAvp !== $currentAvp) {
                $updates['last_avp_name'] = $latestAvp !== '' ? $latestAvp : null;
            }
            if ((int)($client['is_off'] ?? 0) === 1 && !$isRevoked) {
                $updates['is_off'] = 0;
            }

            if ($updates !== []) {
                $this->clients->update($clientId, $updates);
            }
        }
    }

    private function normalizeStatusLabel(string $value): string
    {
        $value = mb_strtolower(trim($value));
        if ($value === '') {
            return '';
        }

        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        $transliterated = iconv('UTF-8', 'ASCII//TRANSLIT', $value);
        if (is_string($transliterated)) {
            $value = strtolower($transliterated);
        }

        return $value;
    }

    private function resolveClientStatus(?int $endAt, bool $isRevoked): string
    {
        if ($isRevoked) {
            return 'inactive';
        }
        if ($endAt === null) {
            return 'prospect';
        }

        $now = now();
        if ($endAt >= $now) {
            return 'active';
        }

        $diff = $now - $endAt;
        if ($diff <= 30 * 86400) {
            return 'recent_expired';
        }

        return 'inactive';
    }

    private function nextFollowUp(?int $endAt): ?int
    {
        if ($endAt === null) {
            return null;
        }
        $followUp = $endAt - (30 * 86400);
        return $followUp > 0 ? $followUp : null;
    }

    private function toTimestamp(mixed $value, bool $dateOnly = false): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            try {
                return ExcelDate::excelToTimestamp((float)$value);
            } catch (\Throwable) {
                return null;
            }
        }

        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        $formats = $dateOnly
            ? ['d/m/Y', 'Y-m-d']
            : ['d/m/Y H:i:s', 'd/m/Y H:i', 'Y-m-d H:i:s', 'Y-m-d H:i', 'd/m/Y'];

        foreach ($formats as $format) {
            $dt = \DateTimeImmutable::createFromFormat($format, $value, new \DateTimeZone(config('app.timezone', 'America/Sao_Paulo')));
            if ($dt instanceof \DateTimeImmutable) {
                return $dt->getTimestamp();
            }
        }

        $ts = strtotime($value);
        if ($ts !== false) {
            return $ts;
        }

        return null;
    }

    private function sanitizeEmail(mixed $email): ?string
    {
        $email = trim(strtolower((string)$email));
        return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
    }

    private function sanitizePhone(mixed $phone): ?string
    {
        $digits = digits_only(is_string($phone) ? $phone : (string)$phone);
        return $digits !== '' ? $digits : null;
    }

    private function sanitizeName(mixed $value): ?string
    {
        $name = trim((string)$value);
        return $name !== '' ? $name : null;
    }

    private function syncPartners(?string ...$names): void
    {
        foreach ($names as $name) {
            if ($name !== null && $name !== '') {
                $this->partners->findOrCreate($name, 'contador');
            }
        }
    }

    private function resolvePath(string $filePath): string
    {
        if (preg_match('/^[A-Z]:/i', $filePath) === 1 || str_starts_with($filePath, '/') || str_starts_with($filePath, '\\')) {
            return $filePath;
        }

        $candidate = base_path($filePath);
        if (file_exists($candidate)) {
            return $candidate;
        }

        return $filePath;
    }

    private function protocolNumber(string $protocol): int
    {
        $digits = preg_replace('/\D+/', '', $protocol) ?? '';
        return $digits === '' ? 0 : (int)$digits;
    }

    private function isNewerOrEqualProtocol(string $incoming, string $current): bool
    {
        return $this->protocolNumber($incoming) >= $this->protocolNumber($current);
    }
}
