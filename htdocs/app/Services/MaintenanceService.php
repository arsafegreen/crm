<?php

declare(strict_types=1);

namespace App\Services;

use App\Database\Connection;
use App\Repositories\TemplateRepository;
use FilesystemIterator;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use ZipArchive;

final class MaintenanceService
{
    public function generateClientBackupZip(): array
    {
        $pdo = Connection::instance();

        $clients = $this->fetchAll($pdo, 'SELECT * FROM clients ORDER BY name ASC');
        $certificates = $this->fetchAll($pdo, 'SELECT * FROM certificates ORDER BY created_at ASC');
        $history = $this->fetchAll(
            $pdo,
            'SELECT h.*, fs.name AS from_stage_name, ts.name AS to_stage_name
             FROM client_stage_history h
             LEFT JOIN pipeline_stages fs ON fs.id = h.from_stage_id
             LEFT JOIN pipeline_stages ts ON ts.id = h.to_stage_id
             ORDER BY h.changed_at ASC'
        );

        $storageDir = storage_path('backups');
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            throw new RuntimeException('Não foi possível preparar a pasta de backups.');
        }

            $timestampToken = date('Ymd_His');
            $excelName = "clientes_backup_{$timestampToken}.xlsx";
        $excelPath = $storageDir . DIRECTORY_SEPARATOR . $excelName;

        $spreadsheet = new Spreadsheet();
        $spreadsheet->getProperties()
            ->setCreator('Marketing Suite')
            ->setTitle('Backup de clientes');

        $clientSheet = $spreadsheet->getActiveSheet();
        $clientSheet->setTitle('Clientes');
        $this->populateSheet($clientSheet, $clients, [
            'id' => 'ID',
            'document' => 'Documento',
            'name' => 'Nome',
            'titular_name' => 'Titular',
            'titular_document' => 'Documento do titular',
            'titular_birthdate' => 'Nascimento titular',
            'email' => 'E-mail',
            'phone' => 'Telefone',
            'whatsapp' => 'WhatsApp',
            'status' => 'Status',
            'status_changed_at' => 'Status atualizado em',
            'document_status' => 'Situação documento',
            'is_off' => 'Carteira Off',
            'last_protocol' => 'Último protocolo',
            'last_avp_name' => 'Último AVP',
            'last_certificate_expires_at' => 'Validade do último certificado',
            'next_follow_up_at' => 'Próximo follow-up',
            'partner_accountant' => 'Parceiro contador',
            'partner_accountant_plus' => 'Parceiro extra',
            'pipeline_stage_id' => 'Etapa do funil (ID)',
            'tags_cache' => 'Tags (cache)',
            'notes' => 'Anotações',
            'created_at' => 'Criado em',
            'updated_at' => 'Atualizado em',
        ]);

        $certSheet = new Worksheet($spreadsheet, 'Certificados');
        $spreadsheet->addSheet($certSheet);
        $this->populateSheet($certSheet, $certificates, [
            'id' => 'ID',
            'client_id' => 'Cliente ID',
            'protocol' => 'Protocolo',
            'product_name' => 'Produto',
            'product_description' => 'Descrição do produto',
            'validity_label' => 'Validade (rótulo)',
            'media_description' => 'Mídia',
            'serial_number' => 'Número de série',
            'status' => 'Status',
            'is_revoked' => 'Revogado',
            'revocation_reason' => 'Motivo revogação',
            'start_at' => 'Início',
            'end_at' => 'Fim',
            'avp_name' => 'AVP Nome',
            'avp_cpf' => 'AVP CPF',
            'aci_name' => 'ACI Nome',
            'aci_cpf' => 'ACI CPF',
            'location_name' => 'Local',
            'location_alias' => 'Apelido do local',
            'city_name' => 'Cidade',
            'emission_type' => 'Tipo emissão',
            'requested_type' => 'Tipo solicitado',
            'partner_name' => 'Parceiro',
            'partner_accountant' => 'Parceiro contador',
            'partner_accountant_plus' => 'Parceiro extra',
            'renewal_protocol' => 'Protocolo de renovação',
            'status_raw' => 'Status original',
            'source_payload' => 'Payload fonte',
            'created_at' => 'Criado em',
            'updated_at' => 'Atualizado em',
        ]);

        $historySheet = new Worksheet($spreadsheet, 'Histórico de etapas');
        $spreadsheet->addSheet($historySheet);
        $this->populateSheet($historySheet, $history, [
            'id' => 'ID',
            'client_id' => 'Cliente ID',
            'from_stage_id' => 'Da etapa (ID)',
            'from_stage_name' => 'Da etapa (nome)',
            'to_stage_id' => 'Para etapa (ID)',
            'to_stage_name' => 'Para etapa (nome)',
            'changed_at' => 'Alterado em',
            'changed_by' => 'Alterado por',
            'notes' => 'Notas',
        ]);

        $writer = new Xlsx($spreadsheet);
        $writer->save($excelPath);

        $zipName = "clientes_backup_{$timestampToken}.zip";
        $zipPath = $storageDir . DIRECTORY_SEPARATOR . $zipName;

        $zip = new ZipArchive();
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            @unlink($excelPath);
            throw new RuntimeException('Não foi possível criar o arquivo compactado.');
        }

        $zip->addFile($excelPath, $excelName);
        $zip->close();
        @unlink($excelPath);

        return [$zipPath, $zipName];
    }

    public function refreshRouteCache(): array
    {
        $cacheDir = storage_path('cache');
        if (!is_dir($cacheDir) && !mkdir($cacheDir, 0775, true) && !is_dir($cacheDir)) {
            throw new RuntimeException('Não foi possível preparar a pasta de cache.');
        }

        $cacheFile = $cacheDir . DIRECTORY_SEPARATOR . 'fastroute.cache.php';

        if (is_file($cacheFile) && !@unlink($cacheFile)) {
            throw new RuntimeException('Não foi possível limpar o cache de rotas.');
        }

        return [
            'cache_file' => $cacheFile,
            'status' => 'cleared',
        ];
    }

    public function generateClientImportSpreadsheet(): array
    {
        $pdo = Connection::instance();

        $records = $this->fetchAll(
            $pdo,
            'SELECT
                c.document AS client_document,
                c.name AS client_name,
                c.titular_name,
                c.titular_document,
                c.titular_birthdate,
                c.email AS titular_email,
                c.phone AS titular_phone,
                cert.protocol,
                cert.product_name,
                cert.product_description,
                cert.validity_label,
                cert.media_description,
                cert.serial_number,
                cert.avp_name,
                cert.avp_cpf,
                cert.aci_name,
                cert.aci_cpf,
                cert.start_at,
                cert.end_at,
                cert.status,
                cert.status_raw,
                cert.is_revoked,
                cert.revocation_reason,
                cert.location_name,
                cert.location_alias,
                cert.city_name,
                cert.partner_name,
                cert.partner_accountant,
                cert.partner_accountant_plus,
                cert.renewal_protocol,
                cert.emission_type,
                cert.requested_type,
                cert.created_at
             FROM certificates cert
             INNER JOIN clients c ON c.id = cert.client_id
             ORDER BY cert.created_at ASC'
        );

        if ($records === []) {
            throw new RuntimeException('Nenhum certificado encontrado para gerar planilha.');
        }

        $storageDir = storage_path('backups');
        if (!is_dir($storageDir) && !mkdir($storageDir, 0775, true) && !is_dir($storageDir)) {
            throw new RuntimeException('Não foi possível preparar a pasta de backups.');
        }

        $timestampToken = date('Ymd_His');
        $excelName = "clientes_import_{$timestampToken}.xlsx";
        $excelPath = $storageDir . DIRECTORY_SEPARATOR . $excelName;

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Certificados');

        $columns = [
            'Protocolo' => static fn(array $row): string => (string)($row['protocol'] ?? ''),
            'Nome' => static fn(array $row): string => strtoupper(trim((string)($row['client_name'] ?? ''))),
            'Documento' => static fn(array $row): string => (string)($row['client_document'] ?? ''),
            'Nome do Titular' => static fn(array $row): string => trim((string)($row['titular_name'] ?? '')),
            'Documento do Titular' => static fn(array $row): string => (string)($row['titular_document'] ?? ''),
            'Data de Nascimento do Titular' => fn(array $row): string => $this->formatDateExport($row['titular_birthdate'] ?? null),
            'E-mail do Titular' => static fn(array $row): string => strtolower(trim((string)($row['titular_email'] ?? ''))),
            'Telefone do Titular' => static fn(array $row): string => digits_only((string)($row['titular_phone'] ?? '')),
            'Produto' => static fn(array $row): string => trim((string)($row['product_name'] ?? '')),
            'Descrição do Produto' => static fn(array $row): string => trim((string)($row['product_description'] ?? '')),
            'Validade' => static fn(array $row): string => trim((string)($row['validity_label'] ?? '')),
            'Descrição Produto Mídia' => static fn(array $row): string => trim((string)($row['media_description'] ?? '')),
            'Numero de Série' => static fn(array $row): string => trim((string)($row['serial_number'] ?? '')),
            'Nome do AVP' => static fn(array $row): string => trim((string)($row['avp_name'] ?? '')),
            'CPF do AVP' => static fn(array $row): string => digits_only((string)($row['avp_cpf'] ?? '')),
            'Nome do ACI' => static fn(array $row): string => trim((string)($row['aci_name'] ?? '')),
            'CPF do ACI' => static fn(array $row): string => digits_only((string)($row['aci_cpf'] ?? '')),
            'Data AVP' => static fn(): string => '',
            'Data Inicio Validade' => fn(array $row): string => $this->formatDateTimeExport($row['start_at'] ?? null),
            'Data Fim Validade' => fn(array $row): string => $this->formatDateTimeExport($row['end_at'] ?? null),
            'Status do Certificado' => fn(array $row): string => $this->formatCertificateStatus($row),
            'Data de Revogação' => static fn(): string => '',
            'Revogado Por' => static fn(): string => '',
            'Código Revogação' => static fn(): string => '',
            'Descrição Revogação' => static fn(array $row): string => trim((string)($row['revocation_reason'] ?? '')),
            'Nome do Local de Atendimento' => static fn(array $row): string => trim((string)($row['location_name'] ?? '')),
            'Apelido do Local de Atendimento' => static fn(array $row): string => trim((string)($row['location_alias'] ?? '')),
            'Nome do Parceiro' => static fn(array $row): string => trim((string)($row['partner_name'] ?? '')),
            'Nome Contador Parceiro' => static fn(array $row): string => trim((string)($row['partner_accountant'] ?? '')),
            'Nome Contador Parceiro Mais' => static fn(array $row): string => trim((string)($row['partner_accountant_plus'] ?? '')),
            'Protocolo Renovação' => static fn(array $row): string => trim((string)($row['renewal_protocol'] ?? '')),
            'Tipo de Emissão Realizada' => fn(array $row): string => $this->formatTitleCase($row['emission_type'] ?? null),
            'Tipo de Emissão Solicitada' => fn(array $row): string => $this->formatTitleCase($row['requested_type'] ?? null),
            'Nome da Cidade' => static fn(array $row): string => trim((string)($row['city_name'] ?? '')),
        ];

        $sheet->fromArray(array_keys($columns), null, 'A1');

        $rowNumber = 2;
        foreach ($records as $record) {
            $values = [];
            foreach ($columns as $callback) {
                $values[] = $callback($record);
            }
            $sheet->fromArray($values, null, 'A' . $rowNumber);
            $rowNumber++;
        }

        for ($col = 1, $max = count($columns); $col <= $max; $col++) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($excelPath);

        return [$excelPath, $excelName];
    }

    public function factoryReset(): void
    {
        $pdo = Connection::instance();

        $tables = [
            'client_tag',
            'tags',
            'campaign_messages',
            'campaigns',
            'automation_jobs',
            'attachments',
            'interactions',
            'tasks',
            'certificate_access_requests',
            'client_stage_history',
            'certificates',
            'partners',
            'pipeline_stages',
            'pipelines',
            'clients',
            'social_accounts',
            'templates',
        ];

        $pdo->beginTransaction();
        try {
            foreach ($tables as $table) {
                $pdo->exec("DELETE FROM {$table}");
            }

            $pdo->prepare('DELETE FROM users WHERE role != :role')->execute([':role' => 'admin']);
            $pdo->exec('UPDATE users SET status = "active", session_token = NULL, session_forced_at = NULL, failed_login_attempts = 0, locked_until = NULL WHERE role = "admin"');

            $this->resetPipelines($pdo);

            $this->resetSequences($pdo, array_merge($tables, ['users']));

            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }

        (new TemplateRepository())->seedDefaults();
        $this->cleanupUploads();
    }

    private function populateSheet(Worksheet $sheet, array $rows, array $columns): void
    {
        $sheet->fromArray(array_values($columns), null, 'A1');

        $rowNumber = 2;
        foreach ($rows as $row) {
            $sheet->fromArray($this->mapRow($row, $columns), null, 'A' . $rowNumber);
            $rowNumber++;
        }

        for ($col = 1; $col <= count($columns); $col++) {
            $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
        }
    }

    private function mapRow(array $row, array $columns): array
    {
        $mapped = [];
        foreach (array_keys($columns) as $key) {
            $value = $row[$key] ?? null;
            $mapped[] = $this->formatValue($key, $value);
        }

        return $mapped;
    }

    private function formatValue(string $key, mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $timestampFields = [
            'created_at',
            'updated_at',
            'status_changed_at',
            'last_certificate_expires_at',
            'next_follow_up_at',
            'start_at',
            'end_at',
            'changed_at',
        ];

        if ($key === 'titular_birthdate') {
            return $this->formatDate((int)$value);
        }

        if (in_array($key, $timestampFields, true)) {
            return $this->formatDateTime((int)$value);
        }

        if (in_array($key, ['is_off', 'is_revoked'], true)) {
            return ((int)$value) === 1 ? 'Sim' : 'Não';
        }

        if (is_scalar($value)) {
            return (string)$value;
        }

        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function formatDate(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        $tz = new \DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone($tz)
            ->format('d/m/Y');
    }

    private function formatDateTime(int $timestamp): string
    {
        if ($timestamp <= 0) {
            return '';
        }

        $tz = new \DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        return (new \DateTimeImmutable('@' . $timestamp))
            ->setTimezone($tz)
            ->format('d/m/Y H:i');
    }

    private function formatDateExport(mixed $timestamp): string
    {
        $normalized = $this->normalizeTimestamp($timestamp);
        return $normalized !== null ? $this->formatDate($normalized) : '';
    }

    private function formatDateTimeExport(mixed $timestamp): string
    {
        $normalized = $this->normalizeTimestamp($timestamp);
        if ($normalized === null || $normalized <= 0) {
            return '';
        }

        $tz = new \DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        return (new \DateTimeImmutable('@' . $normalized))
            ->setTimezone($tz)
            ->format('d/m/Y H:i:s');
    }

    private function normalizeTimestamp(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int)$value;
        }

        return null;
    }

    private function formatCertificateStatus(array $row): string
    {
        $raw = trim((string)($row['status_raw'] ?? ''));
        if ($raw !== '') {
            return $raw;
        }

        $isRevoked = (int)($row['is_revoked'] ?? 0) === 1;
        if ($isRevoked) {
            return 'Revogado';
        }

        $slug = strtolower(trim((string)($row['status'] ?? '')));
        if ($slug === '') {
            return '';
        }

        $map = [
            'emitido' => 'Emitido',
            'pendente' => 'Pendente',
            'revogado' => 'Revogado',
            'cancelado' => 'Cancelado',
            'expirado' => 'Expirado',
            'ativo' => 'Ativo',
        ];

        return $map[$slug] ?? ucfirst(str_replace('_', ' ', $slug));
    }

    private function formatTitleCase(mixed $value): string
    {
        $value = trim((string)$value);
        if ($value === '') {
            return '';
        }

        $value = str_replace(['_', '-'], ' ', $value);
        return mb_convert_case($value, MB_CASE_TITLE, 'UTF-8');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchAll(\PDO $pdo, string $sql): array
    {
        $stmt = $pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
        return $rows !== false ? $rows : [];
    }

    private function resetPipelines(\PDO $pdo): void
    {
        $pdo->exec('DELETE FROM pipeline_stages');
        $pdo->exec('DELETE FROM pipelines');

        $timestamp = time();

        $pipelineStmt = $pdo->prepare('INSERT INTO pipelines (name, description, created_at, updated_at) VALUES (:name, :description, :created_at, :updated_at)');
        $pipelineStmt->execute([
            ':name' => 'Funil Comercial',
            ':description' => 'Fluxo principal de prospecção e renovação',
            ':created_at' => $timestamp,
            ':updated_at' => $timestamp,
        ]);

        $pipelineId = (int)$pdo->lastInsertId();
        if ($pipelineId <= 0) {
            $pipelineId = 1;
        }

        $stages = [
            ['Prospecção', 1, 0],
            ['Contato Inicial', 2, 0],
            ['Envio de Proposta', 3, 0],
            ['Agendamento', 4, 0],
            ['Emissão', 5, 0],
            ['Pós-venda', 6, 0],
            ['Perdido', 7, 1],
            ['Renovado', 8, 1],
        ];

        $stageStmt = $pdo->prepare('INSERT INTO pipeline_stages (pipeline_id, name, position, is_closed, created_at, updated_at) VALUES (:pipeline_id, :name, :position, :is_closed, :created_at, :updated_at)');
        foreach ($stages as [$name, $position, $isClosed]) {
            $stageStmt->execute([
                ':pipeline_id' => $pipelineId,
                ':name' => $name,
                ':position' => $position,
                ':is_closed' => $isClosed,
                ':created_at' => $timestamp,
                ':updated_at' => $timestamp,
            ]);
        }
    }

    private function resetSequences(\PDO $pdo, array $tables): void
    {
        try {
            $quoted = array_map(static fn(string $name): string => "'" . $name . "'", $tables);
            $pdo->exec('DELETE FROM sqlite_sequence WHERE name IN (' . implode(',', $quoted) . ')');
        } catch (\Throwable) {
            // Ignorar se o banco não usar sqlite_sequence.
        }
    }

    private function cleanupUploads(): void
    {
        $uploadsPath = storage_path('uploads');
        if (!is_dir($uploadsPath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($uploadsPath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $fileInfo) {
            $path = $fileInfo->getPathname();
            if ($fileInfo->isDir()) {
                if (!$this->directoryIsEmpty($path)) {
                    continue;
                }
                @rmdir($path);
                continue;
            }

            if (basename($path) === '.gitignore') {
                continue;
            }

            @unlink($path);
        }
    }

    private function directoryIsEmpty(string $path): bool
    {
        $handle = opendir($path);
        if ($handle === false) {
            return true;
        }

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if ($entry === '.gitignore') {
                continue;
            }

            closedir($handle);
            return false;
        }

        closedir($handle);
        return true;
    }
}
