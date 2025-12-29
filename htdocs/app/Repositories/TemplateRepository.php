<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Database\Connection;
use PDO;
use App\Services\TemplatePlaceholderCatalog;
use Throwable;

final class TemplateRepository
{
    private PDO $pdo;
    private bool $tableVerified = false;
    private bool $versionsVerified = false;
    private bool $placeholdersNormalized = false;

    public function __construct()
    {
        $this->pdo = Connection::instance();
    }

    private function ensureTables(): void
    {
        $this->ensureTableExists();
        $this->ensureVersionTables();
    }

    public function all(string $channel = 'email'): array
    {
        $this->ensureTables();
        $this->normalizeExistingPlaceholders();

        $stmt = $this->pdo->prepare(
            'SELECT t.*, v.id AS version_id, v.version AS version_number, v.status AS version_status,
                    v.subject AS version_subject, v.preview_text AS version_preview_text,
                    v.body_html AS version_body_html, v.body_text AS version_body_text,
                    v.updated_at AS version_updated_at
             FROM templates t
             LEFT JOIN template_versions v ON v.id = t.latest_version_id
             WHERE t.channel = :channel
             ORDER BY COALESCE(v.updated_at, t.updated_at) DESC, t.name ASC'
        );
        $stmt->execute([':channel' => $channel]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(function (array $row): array {
            return $this->hydrateTemplateRow($row);
        }, $rows);
    }

    public function find(int $id): ?array
    {
        $this->ensureTables();
        $this->normalizeExistingPlaceholders();

        $stmt = $this->pdo->prepare(
            'SELECT t.*, v.id AS version_id, v.version AS version_number, v.status AS version_status,
                    v.subject AS version_subject, v.preview_text AS version_preview_text,
                    v.body_html AS version_body_html, v.body_text AS version_body_text,
                    v.body_mjml AS version_body_mjml,
                    v.blocks_schema, v.data_schema, v.testing_settings,
                    v.created_by AS version_created_by, v.published_by AS version_published_by,
                    v.created_at AS version_created_at, v.updated_at AS version_updated_at,
                    v.published_at AS version_published_at
             FROM templates t
             LEFT JOIN template_versions v ON v.id = t.latest_version_id
             WHERE t.id = :id
             LIMIT 1'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        $template = $this->hydrateTemplateRow($row);
        $template['versions'] = $this->fetchVersions($id);

        // Hydrate the template fields with the most recent version that actually has content
        if (!empty($template['versions'])) {
            $prefill = $this->pickVersionWithContent($template['versions']);
            if ($prefill !== null) {
                foreach (['subject', 'body_html', 'body_text', 'preview_text'] as $field) {
                    $value = $prefill[$field] ?? null;
                    if ($value !== null && trim((string)$value) !== '') {
                        $template[$field] = $value;
                    }
                }
            }
        }

        return $template;
    }

    public function create(array $data): int
    {
        $this->ensureTables();

        $timestamp = now();
        $templateData = $this->prepareTemplatePayload($data, $timestamp, true);
        $versionData = $this->prepareVersionPayload($data, $timestamp);

        $this->pdo->beginTransaction();

        try {
            $templateId = $this->insertTemplate($templateData);

            if ($versionData !== null) {
                $versionId = $this->insertVersion($templateId, $versionData, 1);
                $this->setLatestVersionId($templateId, $versionId);
            }

            $this->pdo->commit();
            return $templateId;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function update(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $this->ensureTables();

        $timestamp = now();
        $templateData = $this->prepareTemplatePayload($data, $timestamp, false);
        $versionData = $this->prepareVersionPayload($data, $timestamp);

        $this->pdo->beginTransaction();

        try {
            if ($templateData !== []) {
                $this->performTemplateUpdate($id, $templateData);
            }

            if ($versionData !== null) {
                $versionId = $this->insertVersion($id, $versionData, $this->nextVersionNumber($id));
                if ($versionData['status'] === 'published') {
                    $this->setLatestVersionId($id, $versionId);
                }
            }

            $this->pdo->commit();
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    public function delete(int $id): void
    {
        $this->ensureTables();

        $stmt = $this->pdo->prepare('DELETE FROM templates WHERE id = :id');
        $stmt->execute([':id' => $id]);
    }

    public function findVersionById(int $versionId): ?array
    {
        $this->ensureTables();

        $stmt = $this->pdo->prepare('SELECT * FROM template_versions WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $versionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->hydrateVersionRow($row);
    }

    public function seedDefaults(): void
    {
        $this->ensureTables();
        $templates = $this->defaultTemplates();

        foreach ($templates as $template) {
            $name = (string)($template['name'] ?? '');
            $channel = (string)($template['channel'] ?? 'email');
            if ($name === '') {
                continue;
            }

            $existing = $this->findByName($name, $channel);
            if ($existing === null) {
                $this->create($template);
                continue;
            }

            // Update defaults in place to keep them fresh/elegant.
            $this->update((int)$existing['id'], $template);
        }
    }

    public function findByName(string $name, string $channel = 'email'): ?array
    {
        $this->ensureTables();

        $stmt = $this->pdo->prepare('SELECT * FROM templates WHERE name = :name AND channel = :channel LIMIT 1');
        $stmt->execute([
            ':name' => $name,
            ':channel' => $channel,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrateTemplateRow($row) : null;
    }

    private function hydrateTemplateRow(array $row): array
    {
        $template = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'channel' => (string)$row['channel'],
            'slug' => $row['slug'] ?? null,
            'category' => $row['category'] ?? null,
            'description' => $row['description'] ?? null,
            'preview_text' => $row['version_preview_text'] ?? $row['preview_text'] ?? null,
            'status' => $row['status'] ?? 'active',
            'editor_mode' => $row['editor_mode'] ?? 'html',
            'tags' => $this->decodeJsonColumn($row['tags'] ?? null),
            'settings' => $this->decodeJsonColumn($row['settings'] ?? null),
            'metadata' => $this->decodeJsonColumn($row['metadata'] ?? null),
            'thumbnail_path' => $row['thumbnail_path'] ?? null,
            'locked_by' => isset($row['locked_by']) ? (int)$row['locked_by'] : null,
            'locked_at' => isset($row['locked_at']) ? (int)$row['locked_at'] : null,
            'latest_version_id' => isset($row['latest_version_id']) ? (int)$row['latest_version_id'] : null,
            'subject' => $row['version_subject'] ?? $row['subject'] ?? null,
            'body_html' => $row['version_body_html'] ?? $row['body_html'] ?? null,
            'body_text' => $row['version_body_text'] ?? $row['body_text'] ?? null,
            'created_at' => isset($row['created_at']) ? (int)$row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (int)$row['updated_at'] : null,
        ];

        if (!empty($row['version_id'])) {
            $template['latest_version'] = [
                'id' => (int)$row['version_id'],
                'version' => isset($row['version_number']) ? (int)$row['version_number'] : null,
                'status' => $row['version_status'] ?? null,
                'updated_at' => isset($row['version_updated_at']) ? (int)$row['version_updated_at'] : null,
            ];
        } else {
            $template['latest_version'] = null;
        }

        return $template;
    }

    private function fetchVersions(int $templateId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM template_versions WHERE template_id = :template_id ORDER BY version DESC');
        $stmt->execute([':template_id' => $templateId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return array_map(fn(array $row): array => $this->hydrateVersionRow($row), $rows);
    }

    private function pickVersionWithContent(array $versions): ?array
    {
        foreach ($versions as $version) {
            $subject = trim((string)($version['subject'] ?? ''));
            $html = trim((string)($version['body_html'] ?? ''));
            $text = trim((string)($version['body_text'] ?? ''));
            $preview = trim((string)($version['preview_text'] ?? ''));
            if ($subject !== '' || $html !== '' || $text !== '' || $preview !== '') {
                return $version;
            }
        }

        return $versions[0] ?? null;
    }

    private function hydrateVersionRow(array $row): array
    {
        return [
            'id' => (int)$row['id'],
            'template_id' => (int)$row['template_id'],
            'version' => (int)$row['version'],
            'label' => $row['label'] ?? null,
            'status' => $row['status'] ?? 'draft',
            'source_format' => $row['source_format'] ?? 'html',
            'subject' => $row['subject'] ?? null,
            'preview_text' => $row['preview_text'] ?? null,
            'body_html' => $row['body_html'] ?? null,
            'body_text' => $row['body_text'] ?? null,
            'body_mjml' => $row['body_mjml'] ?? null,
            'blocks_schema' => $this->decodeJsonColumn($row['blocks_schema'] ?? null),
            'data_schema' => $this->decodeJsonColumn($row['data_schema'] ?? null),
            'testing_settings' => $this->decodeJsonColumn($row['testing_settings'] ?? null),
            'checksum' => $row['checksum'] ?? null,
            'notes' => $row['notes'] ?? null,
            'created_by' => isset($row['created_by']) ? (int)$row['created_by'] : null,
            'published_by' => isset($row['published_by']) ? (int)$row['published_by'] : null,
            'published_at' => isset($row['published_at']) ? (int)$row['published_at'] : null,
            'created_at' => isset($row['created_at']) ? (int)$row['created_at'] : null,
            'updated_at' => isset($row['updated_at']) ? (int)$row['updated_at'] : null,
        ];
    }

    private function prepareTemplatePayload(array $data, int $timestamp, bool $isCreate): array
    {
        $fields = [
            'name', 'channel', 'slug', 'category', 'description', 'preview_text',
            'status', 'editor_mode', 'tags', 'settings', 'metadata', 'thumbnail_path',
            'locked_by', 'locked_at', 'latest_version_id', 'subject', 'body_html', 'body_text'
        ];

        $payload = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if ($payload === []) {
            return $payload;
        }

        $payload = $this->normalizeContent($payload);
        $payload = $this->normalizePlaceholders($payload);
        $payload = $this->encodeTemplateJson($payload);
        $payload['updated_at'] = $timestamp;

        if ($isCreate) {
            $payload['created_at'] = $payload['created_at'] ?? $timestamp;
        }

        if (!isset($payload['channel'])) {
            $payload['channel'] = 'email';
        }

        return $payload;
    }

    private function prepareVersionPayload(array $data, int $timestamp): ?array
    {
        $fields = [
            'subject', 'preview_text', 'body_html', 'body_text', 'body_mjml',
            'blocks_schema', 'data_schema', 'testing_settings', 'checksum', 'notes',
            'status', 'label', 'source_format', 'published_at', 'created_by', 'published_by'
        ];

        $payload = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $data)) {
                $payload[$field] = $data[$field];
            }
        }

        if ($payload === []) {
            return null;
        }

        $payload = $this->normalizeContent($payload);
        $payload = $this->normalizePlaceholders($payload);
        $payload = $this->encodeVersionJson($payload);
        $payload['source_format'] = $payload['source_format'] ?? 'html';

        $status = $payload['status'] ?? 'published';
        $payload['status'] = in_array($status, ['draft', 'published'], true) ? $status : 'draft';
        $payload['created_at'] = $payload['created_at'] ?? $timestamp;
        $payload['updated_at'] = $timestamp;

        if ($payload['status'] === 'published') {
            if (!isset($payload['published_at'])) {
                $payload['published_at'] = $timestamp;
            }
        } else {
            $payload['published_at'] = null;
            $payload['published_by'] = $payload['published_by'] ?? null;
        }

        return $payload;
    }

    private function encodeTemplateJson(array $data): array
    {
        foreach (['metadata', 'tags', 'settings'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if (is_array($value)) {
                    $data[$field] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif ($value === null || is_string($value)) {
                    $data[$field] = $value;
                } else {
                    $data[$field] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }

        return $data;
    }

    private function encodeVersionJson(array $data): array
    {
        foreach (['blocks_schema', 'data_schema', 'testing_settings'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                if (is_array($value)) {
                    $data[$field] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                } elseif ($value === null || is_string($value)) {
                    $data[$field] = $value;
                } else {
                    $data[$field] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                }
            }
        }

        return $data;
    }

    private function insertTemplate(array $data): int
    {
        if ($data === []) {
              throw new \RuntimeException('Template data is required.');
        }

        $fields = array_keys($data);
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, $fields));

        $stmt = $this->pdo->prepare("INSERT INTO templates ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($this->prefixArrayKeys($data));

        return (int)$this->pdo->lastInsertId();
    }

    private function performTemplateUpdate(int $id, array $data): void
    {
        if ($data === []) {
            return;
        }

        $fields = array_keys($data);
        $assignments = implode(', ', array_map(static fn(string $field): string => sprintf('%s = :%s', $field, $field), $fields));
        $data['id'] = $id;

        $stmt = $this->pdo->prepare("UPDATE templates SET {$assignments} WHERE id = :id");
        $stmt->execute($this->prefixArrayKeys($data));
    }

    private function insertVersion(int $templateId, array $data, int $version): int
    {
        $payload = $data;
        $payload['template_id'] = $templateId;
        $payload['version'] = $version;
        $payload['label'] = $payload['label'] ?? sprintf('Versão %d', $version);

        $fields = array_keys($payload);
        $columns = implode(', ', $fields);
        $placeholders = implode(', ', array_map(static fn(string $field): string => ':' . $field, $fields));

        $stmt = $this->pdo->prepare("INSERT INTO template_versions ({$columns}) VALUES ({$placeholders})");
        $stmt->execute($this->prefixArrayKeys($payload));

        return (int)$this->pdo->lastInsertId();
    }

    private function nextVersionNumber(int $templateId): int
    {
        $stmt = $this->pdo->prepare('SELECT MAX(version) FROM template_versions WHERE template_id = :template_id');
        $stmt->execute([':template_id' => $templateId]);
        $current = $stmt->fetchColumn();

        return (int)$current + 1;
    }

    private function setLatestVersionId(int $templateId, int $versionId): void
    {
        $stmt = $this->pdo->prepare('UPDATE templates SET latest_version_id = :version_id, updated_at = :updated_at WHERE id = :template_id');
        $stmt->execute([
            ':version_id' => $versionId,
            ':updated_at' => now(),
            ':template_id' => $templateId,
        ]);
    }

    private function decodeJsonColumn($value)
    {
        if ($value === null || $value === '') {
            return [];
        }

        if (is_array($value)) {
            return $value;
        }

        $decoded = json_decode((string)$value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            return $decoded;
        }

        return $value;
    }

    private function defaultTemplates(): array
    {
        return [
            [
                'name' => 'Renovação premium',
                'channel' => 'email',
                'subject' => 'Seu certificado digital merece prioridade',
                'body_html' => $this->templateRenewalPremium(),
                'body_text' => $this->plainText($this->templateRenewalPremium()),
                'metadata' => ['category' => 'renovacao'],
            ],
            [
                'name' => 'Renovação WhatsApp',
                'channel' => 'email',
                'subject' => 'Renove seu certificado digital pelo WhatsApp',
                'body_html' => $this->templateRenewalWhatsapp(),
                'body_text' => $this->plainText($this->templateRenewalWhatsapp()),
                'metadata' => ['category' => 'renovacao', 'tags' => ['whatsapp', 'renovacao']],
            ],
            [
                'name' => 'Reativação consultiva',
                'channel' => 'email',
                'subject' => 'Vamos renovar seu certificado com comodidade?',
                'body_html' => $this->templateConsultative(),
                'body_text' => $this->plainText($this->templateConsultative()),
                'metadata' => ['category' => 'reativacao'],
            ],
            [
                'name' => 'Ofertas de fidelidade',
                'channel' => 'email',
                'subject' => 'Benefícios exclusivos para clientes SafeGreen',
                'body_html' => $this->templateLoyalty(),
                'body_text' => $this->plainText($this->templateLoyalty()),
                'metadata' => ['category' => 'beneficios'],
            ],
            [
                'name' => 'Aniversário',
                'channel' => 'email',
                'subject' => 'Feliz aniversário, {{nome}}!',
                'body_html' => $this->templateBirthday(),
                'body_text' => $this->plainText($this->templateBirthday()),
                'metadata' => ['category' => 'aniversario'],
            ],
            [
                'name' => 'Natal',
                'channel' => 'email',
                'subject' => 'Feliz Natal de {{empresa}}',
                'body_html' => $this->templateChristmas(),
                'body_text' => $this->plainText($this->templateChristmas()),
                'metadata' => ['category' => 'natal'],
            ],
            [
                'name' => 'Ano novo',
                'channel' => 'email',
                'subject' => 'Um ótimo Ano Novo!',
                'body_html' => $this->templateNewYear(),
                'body_text' => $this->plainText($this->templateNewYear()),
                'metadata' => ['category' => 'ano_novo'],
            ],
        ];
    }

    private function templateRenewalWhatsapp(): string
    {
        $waLink = 'https://wa.me/551936296525?text=Quero%20renovar%20meu%20certificado%20digital';

        return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-family:'Segoe UI',Arial,sans-serif;background:#0b1220;color:#e2e8f0;padding:0;margin:0;">
    <tr>
        <td align="center" style="padding:42px 14px;">
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;max-width:560px;background:#0f172a;border-radius:18px;overflow:hidden;border:1px solid rgba(56,189,248,0.24);box-shadow:0 18px 44px -26px rgba(14,165,233,0.45);">
                <tr>
                    <td style="padding:18px 22px 0;background:linear-gradient(135deg,rgba(56,189,248,0.18),rgba(16,185,129,0.2));">
                        <p style="margin:0;font-size:12px;letter-spacing:0.12em;text-transform:uppercase;color:#cffafe;font-weight:700;">Renovação digital</p>
                        <h1 style="margin:6px 0 0;font-size:26px;font-weight:800;color:#e2e8f0;line-height:1.35;">{{nome}}, renove com 1 clique no WhatsApp</h1>
                        <p style="margin:14px 0 0;font-size:15px;line-height:1.65;color:#cbd5e1;">
                            Seu certificado expira em <strong>{{certificate_expires_at_formatted}}</strong>. Para não ficar sem acesso, é só falar conosco no WhatsApp e ajustar o melhor horário de atendimento.
                        </p>
                        <p style="margin:10px 0 14px;font-size:14px;color:#94a3b8;">Atendimento humano, sem fila. Se preferir, apenas responda este e-mail.</p>
                        <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 20px 0;">
                            <tr>
                                <td align="center" style="background:#22c55e;border-radius:999px;">
                                    <a href="$waLink" style="display:inline-block;padding:14px 22px;color:#0b1220;font-weight:800;text-decoration:none;font-size:15px;letter-spacing:0.02em;">Falar no WhatsApp</a>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:18px 22px 22px;background:#0f172a;">
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;">
                            <tr>
                                <td style="background:rgba(15,118,110,0.14);border:1px solid rgba(56,189,248,0.16);border-radius:14px;padding:16px 18px;">
                                    <p style="margin:0;font-size:13px;line-height:1.6;color:#cbd5e1;">
                                        <strong>Por que antecipar?</strong><br>
                                        Evita bloqueios, mantém NF-e e e-CNPJ ativos, e garante suporte rápido. Se já renovou, desconsidere este aviso.
                                    </p>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:16px 0 0;font-size:13px;color:#94a3b8;">Equipe SafeGreen · (19) 3629-6525</p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
HTML;
    }

    private function templateBirthday(): string
    {
        return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-family:'Segoe UI',Arial,sans-serif;background:#eef2ff;color:#0f172a;padding:0;margin:0;">
    <tr>
        <td align="center" style="padding:48px 14px;">
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;max-width:560px;background:#ffffff;border-radius:22px;overflow:hidden;border:1px solid #d1f5d7;box-shadow:0 20px 50px -28px rgba(34,197,94,0.26);">
                <tr>
                    <td style="padding:0 0 4px 0; background:linear-gradient(120deg,#16a34a 0%,#22c55e 45%,#34d399 100%);"></td>
                </tr>
                <tr>
                    <td style="padding:30px 26px 12px 26px;">
                        <p style="margin:0 0 10px;font-size:13px;letter-spacing:0.14em;text-transform:uppercase;color:#0f172a;font-weight:700;">Dia de celebrar você</p>
                        <h1 style="margin:0;font-size:32px;font-weight:800;color:#111827;line-height:1.2;">Feliz aniversário, {{nome}}!</h1>
                        <p style="margin:18px 0 0;font-size:16px;line-height:1.7;color:#1f2937;">
                            Hoje é pausa para sorrir, receber carinho e lembrar que você é prioridade. Desejamos um dia leve, com tempo para quem importa e espaço para o que te faz feliz.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 26px 20px 26px;">
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;">
                            <tr>
                                <td style="background:linear-gradient(135deg,rgba(34,211,238,0.12),rgba(163,230,53,0.14));border:1px solid rgba(34,197,94,0.26);border-radius:18px;padding:22px 24px;">
                                    <p style="margin:0;font-size:15px;line-height:1.65;color:#0f172a;">
                                        De nossa equipe para você: que venham novas conquistas, saúde e momentos inesquecíveis. Se pudermos ajudar em algo, conte conosco. Estamos aqui para facilitar seu dia, não só hoje.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 26px 28px 26px;">
                        <p style="margin:0;font-size:15px;color:#0f172a;font-weight:700;">Com carinho,</p>
                        <p style="margin:6px 0 0;font-size:14px;color:#1f2937;">SafeGreen Certificado Digital</p>
                        <p style="margin:8px 0 0;font-size:13px;color:#1f2937;">
                            Fone/Whatsapp (19)3629-6525<br>
                            instagram: <a href="https://www.instagram.com/safegreen_certificado_digital" style="color:#15803d;font-weight:600;text-decoration:none;">@safegreen_certificado_digital</a> ·
                            Site: <a href="https://safegreen.com.br" style="color:#15803d;font-weight:600;text-decoration:none;">safegreen.com.br</a>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
HTML;
    }

    private function templateChristmas(): string
    {
        return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-family:'Segoe UI',Arial,sans-serif;background:#f3f9ff;color:#0f172a;padding:0;margin:0;">
    <tr>
        <td align="center" style="padding:48px 14px;">
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;max-width:560px;background:#ffffff;border-radius:22px;overflow:hidden;border:1px solid #c7f9cc;box-shadow:0 20px 48px -28px rgba(34,197,94,0.3);">
                <tr>
                    <td style="padding:0;height:10px;background:linear-gradient(115deg,#16a34a 0%,#22c55e 45%,#34d399 100%);"></td>
                </tr>
                <tr>
                    <td style="padding:30px 26px 12px 26px;">
                        <p style="margin:0 0 10px;font-size:13px;letter-spacing:0.16em;text-transform:uppercase;color:#0f172a;font-weight:700;">Boas festas com leveza</p>
                        <h1 style="margin:0;font-size:32px;font-weight:800;color:#111827;line-height:1.25;">Feliz Natal, {{nome}}!</h1>
                        <p style="margin:18px 0 0;font-size:16px;line-height:1.7;color:#1f2937;">
                            Que o seu Natal seja iluminado por encontros sinceros, abraços demorados e aquela tranquilidade que renova as energias. Se precisar de algo, estamos por perto para facilitar e cuidar.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 26px 20px 26px;">
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;">
                            <tr>
                                <td style="background:linear-gradient(135deg,rgba(74,222,128,0.14),rgba(14,165,233,0.12));border:1px solid rgba(34,197,94,0.3);border-radius:18px;padding:22px 24px;">
                                    <p style="margin:0;font-size:15px;line-height:1.7;color:#0f172a;">
                                        Agradecemos pela confiança ao longo do ano. Desejamos uma noite tranquila e uma virada cheia de boas notícias para você e para quem está ao seu lado.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 26px 28px 26px;">
                        <p style="margin:0;font-size:15px;color:#0f172a;font-weight:700;">Boas festas!</p>
                        <p style="margin:6px 0 0;font-size:14px;color:#1f2937;">SafeGreen Certificado Digital</p>
                        <p style="margin:8px 0 0;font-size:13px;color:#1f2937;">
                            Fone/Whatsapp (19)3629-6525<br>
                            instagram: <a href="https://www.instagram.com/safegreen_certificado_digital" style="color:#15803d;font-weight:600;text-decoration:none;">@safegreen_certificado_digital</a> ·
                            Site: <a href="https://safegreen.com.br" style="color:#15803d;font-weight:600;text-decoration:none;">safegreen.com.br</a>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
HTML;
    }

    private function templateNewYear(): string
    {
        return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-family:'Segoe UI',Arial,sans-serif;background:#eefbf4;color:#0f172a;padding:0;margin:0;">
    <tr>
        <td align="center" style="padding:48px 14px;">
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;max-width:560px;background:#ffffff;border-radius:22px;overflow:hidden;border:1px solid #bbf7d0;box-shadow:0 20px 48px -28px rgba(34,197,94,0.3);">
                <tr>
                    <td style="padding:0;height:10px;background:linear-gradient(120deg,#16a34a 0%,#22c55e 40%,#34d399 100%);"></td>
                </tr>
                <tr>
                    <td style="padding:30px 26px 12px 26px;">
                        <p style="margin:0 0 10px;font-size:13px;letter-spacing:0.14em;text-transform:uppercase;color:#0f172a;font-weight:700;">Bem-vindo, {{ano}}</p>
                        <h1 style="margin:0;font-size:32px;font-weight:800;color:#111827;line-height:1.25;">Feliz Ano Novo, {{nome}}!</h1>
                        <p style="margin:18px 0 0;font-size:16px;line-height:1.7;color:#1f2937;">
                            Que {{ano}} traga saúde, prosperidade e tempo para o que faz sentido. Estamos aqui para apoiar seus planos e deixar os processos mais simples sempre que precisar.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 26px 20px 26px;">
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;">
                            <tr>
                                <td style="background:linear-gradient(135deg,rgba(52,211,153,0.12),rgba(250,204,21,0.14));border:1px solid rgba(52,211,153,0.28);border-radius:18px;padding:22px 24px;">
                                    <p style="margin:0;font-size:15px;line-height:1.7;color:#0f172a;">
                                        Reserve um momento para celebrar as pequenas vitórias e recarregar as energias. Se algo depender da gente, pode contar: vamos caminhar junto com você e com a sua empresa em cada etapa.
                                    </p>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 26px 28px 26px;">
                        <p style="margin:0;font-size:15px;color:#0f172a;font-weight:700;">Um ótimo início!</p>
                        <p style="margin:6px 0 0;font-size:14px;color:#1f2937;">SafeGreen Certificado Digital</p>
                        <p style="margin:8px 0 0;font-size:13px;color:#1f2937;">
                            Fone/Whatsapp (19)3629-6525<br>
                            instagram: <a href="https://www.instagram.com/safegreen_certificado_digital" style="color:#15803d;font-weight:600;text-decoration:none;">@safegreen_certificado_digital</a> ·
                            Site: <a href="https://safegreen.com.br" style="color:#15803d;font-weight:600;text-decoration:none;">safegreen.com.br</a>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
HTML;
    }

    private function normalizeContent(array $data): array
    {
        foreach (['subject', 'preview_text'] as $field) {
            if (array_key_exists($field, $data)) {
                $value = $data[$field];
                $value = is_string($value) ? trim($value) : null;
                $data[$field] = $value === '' ? null : $value;
            }
        }

        if (array_key_exists('body_html', $data)) {
            $html = $data['body_html'];
            $html = is_string($html) ? trim($html) : null;
            $data['body_html'] = $html === '' ? null : $html;
        }

        if (array_key_exists('body_text', $data)) {
            $text = $data['body_text'];
            $text = is_string($text) ? trim($text) : null;
            $data['body_text'] = $text === '' ? null : $text;
        }

        if (array_key_exists('body_mjml', $data)) {
            $mjml = $data['body_mjml'];
            $mjml = is_string($mjml) ? trim($mjml) : null;
            $data['body_mjml'] = $mjml === '' ? null : $mjml;
        }

        return $data;
    }

    private function normalizePlaceholders(array $payload): array
    {
        foreach (['subject', 'preview_text', 'body_html', 'body_text', 'body_mjml'] as $field) {
            if (!array_key_exists($field, $payload)) {
                continue;
            }

            $value = $payload[$field];
            if (is_string($value)) {
                $payload[$field] = TemplatePlaceholderCatalog::normalizeContent($value);
            }
        }

        return $payload;
    }

    private function normalizeExistingPlaceholders(): void
    {
        if ($this->placeholdersNormalized) {
            return;
        }

        $this->ensureTables();
        $now = now();

        $this->pdo->beginTransaction();

        try {
            $templates = $this->pdo->query('SELECT id, subject, preview_text, body_html, body_text FROM templates')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($templates as $row) {
                $changes = $this->diffNormalizedPlaceholders($row, ['subject', 'preview_text', 'body_html', 'body_text']);
                if ($changes === []) {
                    continue;
                }

                $changes['updated_at'] = $now;
                $assignments = [];
                $params = [':id' => (int)$row['id']];

                foreach ($changes as $field => $value) {
                    $assignments[] = sprintf('%s = :%s', $field, $field);
                    $params[':' . $field] = $value;
                }

                $stmt = $this->pdo->prepare('UPDATE templates SET ' . implode(', ', $assignments) . ' WHERE id = :id');
                $stmt->execute($params);
            }

            $versions = $this->pdo->query('SELECT id, subject, preview_text, body_html, body_text, body_mjml FROM template_versions')->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($versions as $row) {
                $changes = $this->diffNormalizedPlaceholders($row, ['subject', 'preview_text', 'body_html', 'body_text', 'body_mjml']);
                if ($changes === []) {
                    continue;
                }

                $changes['updated_at'] = $now;
                $assignments = [];
                $params = [':id' => (int)$row['id']];

                foreach ($changes as $field => $value) {
                    $assignments[] = sprintf('%s = :%s', $field, $field);
                    $params[':' . $field] = $value;
                }

                $stmt = $this->pdo->prepare('UPDATE template_versions SET ' . implode(', ', $assignments) . ' WHERE id = :id');
                $stmt->execute($params);
            }

            $this->pdo->commit();
            $this->placeholdersNormalized = true;
        } catch (Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
    }

    private function diffNormalizedPlaceholders(array $row, array $fields): array
    {
        $subset = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $row)) {
                $subset[$field] = $row[$field];
            }
        }

        if ($subset === []) {
            return [];
        }

        $normalized = $this->normalizePlaceholders($subset);
        $changes = [];

        foreach ($subset as $field => $original) {
            $newValue = $normalized[$field] ?? null;
            if ($newValue !== $original) {
                $changes[$field] = $newValue;
            }
        }

        return $changes;
    }

    private function plainText(string $html): string
    {
        $text = strip_tags($html);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text ?? '') ?? '';
        return trim($text);
    }

    private function prefixArrayKeys(array $data): array
    {
        $prefixed = [];
        foreach ($data as $key => $value) {
            $prefixed[':' . $key] = $value;
        }
        return $prefixed;
    }

    private function ensureTableExists(): void
    {
        if ($this->tableVerified) {
            return;
        }

        $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $stmt->execute([':table' => 'templates']);
        $exists = (bool)$stmt->fetchColumn();

        if (!$exists) {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS templates (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    name TEXT NOT NULL,
                    channel TEXT NOT NULL,
                    subject TEXT NOT NULL,
                    body_html TEXT NULL,
                    body_text TEXT NULL,
                    metadata TEXT NULL,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL
                )'
            );


                        $stmt = $this->pdo->prepare("PRAGMA table_info(campaigns)");
                        $stmt->execute();
                        $columns = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
                        $hasTemplateId = false;
                        foreach ($columns as $info) {
                            if (($info['name'] ?? '') === 'template_id') {
                                $hasTemplateId = true;
                                break;
                            }
                        }

                        if (!$hasTemplateId) {
                            $this->pdo->exec('ALTER TABLE campaigns ADD COLUMN template_id INTEGER NULL');
                        }
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_templates_channel ON templates(channel)');
        }

        $this->tableVerified = true;

        $this->ensureVersionTables();
    }

    private function ensureVersionTables(): void
    {
        if ($this->versionsVerified) {
            return;
        }

        $stmt = $this->pdo->prepare("SELECT name FROM sqlite_master WHERE type = 'table' AND name = :table LIMIT 1");
        $stmt->execute([':table' => 'template_versions']);
        $exists = (bool)$stmt->fetchColumn();

        if (!$exists) {
            $this->pdo->exec(
                'CREATE TABLE IF NOT EXISTS template_versions (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    template_id INTEGER NOT NULL,
                    version INTEGER NOT NULL,
                    label TEXT NULL,
                    status TEXT NOT NULL DEFAULT "draft",
                    source_format TEXT NOT NULL DEFAULT "html",
                    subject TEXT NULL,
                    preview_text TEXT NULL,
                    body_html TEXT NULL,
                    body_text TEXT NULL,
                    body_mjml TEXT NULL,
                    blocks_schema TEXT NULL,
                    data_schema TEXT NULL,
                    testing_settings TEXT NULL,
                    checksum TEXT NULL,
                    notes TEXT NULL,
                    created_by INTEGER NULL,
                    published_by INTEGER NULL,
                    published_at INTEGER NULL,
                    created_at INTEGER NOT NULL,
                    updated_at INTEGER NOT NULL,
                    FOREIGN KEY (template_id) REFERENCES templates(id) ON DELETE CASCADE,
                    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
                    FOREIGN KEY (published_by) REFERENCES users(id) ON DELETE SET NULL
                )'
            );

            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_template_versions_template ON template_versions(template_id)');
            $this->pdo->exec('CREATE INDEX IF NOT EXISTS idx_template_versions_status ON template_versions(status)');
            $this->pdo->exec('CREATE UNIQUE INDEX IF NOT EXISTS idx_template_versions_unique ON template_versions(template_id, version)');
        }

        $this->versionsVerified = true;
    }

    private function templateRenewalPremium(): string
    {
        return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#eefbf4" style="font-family:'Segoe UI',Arial,sans-serif;background:#eefbf4;color:#0f172a;padding:0;margin:0;">
    <tr>
        <td align="center" style="padding:38px 14px;">
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#ffffff" style="width:100%;max-width:560px;background:#ffffff;border-radius:22px;overflow:hidden;border:1px solid #bbf7d0;box-shadow:0 20px 48px -28px rgba(34,197,94,0.28);">
                <tr>
                    <td bgcolor="#16a34a" style="padding:0;height:9px;background:#16a34a;"></td>
                </tr>
                <tr>
                    <td style="padding:30px 26px 16px 26px;">
                        <p style="margin:0 0 10px;font-size:13px;letter-spacing:0.14em;text-transform:uppercase;color:#0f172a;font-weight:700;">Cuidado antes da pressa</p>
                        <h1 style="margin:0;font-size:30px;font-weight:800;color:#111827;line-height:1.25;">Renove seu certificado sem correria</h1>
                        <p style="margin:18px 0 0;font-size:16px;line-height:1.7;color:#1f2937;">
                            Olá {{client_name}}, identificamos que seu certificado digital vence em <strong>{{certificate_expires_at_formatted}}</strong>. Reservamos uma rota preferencial para que você renove com tranquilidade, sem filas e com acompanhamento próximo.
                        </p>
                        <p style="margin:14px 0 0;font-size:15px;line-height:1.7;color:#1f2937;">
                            Se for sobre sua empresa {{empresa}}, ou outro titular, basta responder este e-mail: ajustamos tudo para o contexto certo e evitamos retrabalho.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 26px 18px 26px;">
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;">
                            <tr>
                                <td bgcolor="#e8f7ef" style="padding:22px 24px;border-radius:18px;background:#e8f7ef;border:1px solid #bbf7d0;">
                                    <h2 style="margin:0 0 10px;font-size:19px;color:#0f172a;">Seu pacote premium inclui</h2>
                                    <ul style="margin:0;padding-left:18px;color:#1f2937;font-size:15px;line-height:1.75;">
                                        <li>Agenda priorizada (presencial ou online) e confirmação rápida</li>
                                        <li>Checklist guiado com o que cada documento precisa conter</li>
                                        <li>Suporte após a emissão para garantir que tudo funcione</li>
                                    </ul>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 26px 26px 26px;">
                        <table align="center" role="presentation" cellpadding="0" cellspacing="0" style="margin:0 auto;">
                            <tr>
                                <td align="center" bgcolor="#22c55e" style="border-radius:999px;background:#22c55e;">
                                    <a href="https://wa.me/551936296525" style="display:inline-block;padding:15px 34px;font-size:16px;font-weight:700;color:#0f172a;text-decoration:none;">Reservar meu horário</a>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:18px 0 0;font-size:13px;color:#1f2937;">
                            Se já concluiu a renovação, desconsidere. Caso precise de outra data ou de dados da empresa, nos avise: cuidamos disso para você.
                        </p>
                        <p style="margin:18px 0 0;font-size:14px;color:#1f2937;font-weight:700;">SafeGreen Certificado Digital</p>
                        <p style="margin:8px 0 0;font-size:13px;color:#1f2937;">
                            Fone/Whatsapp (19)3629-6525<br>
                            instagram: <a href="https://www.instagram.com/safegreen_certificado_digital" style="color:#15803d;font-weight:600;text-decoration:none;">@safegreen_certificado_digital</a> ·
                            Site: <a href="https://safegreen.com.br" style="color:#15803d;font-weight:600;text-decoration:none;">safegreen.com.br</a>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
HTML;
    }

    private function templateConsultative(): string
    {
        return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#f3f9ff" style="font-family:'Segoe UI',Arial,sans-serif;background:#f3f9ff;color:#0f172a;padding:0;margin:0;">
    <tr>
        <td align="center" style="padding:38px 14px;">
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" bgcolor="#ffffff" style="width:100%;max-width:560px;background:#ffffff;border-radius:22px;overflow:hidden;border:1px solid #c7f9cc;box-shadow:0 20px 48px -26px rgba(34,197,94,0.3);">
                <tr>
                    <td bgcolor="#16a34a" style="padding:0;height:9px;background:#16a34a;"></td>
                </tr>
                <tr>
                    <td style="padding:28px 24px 14px 24px;text-align:left;">
                        <h1 style="margin:0;font-size:30px;font-weight:800;color:#0f172a;">Vamos renovar seu certificado com calma?</h1>
                        <p style="margin:16px 0 0;font-size:16px;line-height:1.7;color:#1f2937;">
                            Olá {{client_name}}, seu certificado expira em <strong>{{certificate_expires_at_formatted}}</strong>. Para evitar correria, já deixamos um caminho pronto e flexível para você e, se precisar, para a empresa {{empresa}}.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 24px 18px 24px;">
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;">
                            <tr>
                                <td bgcolor="#e8f7ef" style="padding:20px 22px;border-radius:16px;background:#e8f7ef;border:1px solid #9fe3c0;">
                                    <h2 style="margin:0 0 10px;font-size:18px;color:#0f172a;">Como vamos te ajudar</h2>
                                    <ol style="margin:0;padding-left:18px;color:#1f2937;font-size:15px;line-height:1.65;">
                                        <li>Confirmamos o melhor horário e o formato (online ou presencial).</li>
                                        <li>Enviamos um checklist com o que cada documento precisa trazer.</li>
                                        <li>Seguimos juntos na emissão e depois, para garantir que tudo funcione.</li>
                                    </ol>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 24px 26px 24px;text-align:left;">
                        <a href="mailto:atendimento@safegreen.com.br" style="display:inline-block;padding:14px 30px;border-radius:12px;background:linear-gradient(120deg,#22d3ee,#60a5fa);border:1px solid rgba(59,130,246,0.35);font-weight:700;color:#0f172a;text-decoration:none;font-size:15px;">Quero alinhar agora</a>
                        <p style="margin:16px 0 0;font-size:13px;color:#1f2937;">Prefere WhatsApp? Só responder com o melhor número e horário, cuidamos de tudo para você.</p>
                        <p style="margin:18px 0 0;font-size:14px;color:#1f2937;font-weight:700;">SafeGreen Certificado Digital</p>
                        <p style="margin:8px 0 0;font-size:13px;color:#1f2937;">
                            Fone/Whatsapp (19)3629-6525<br>
                            instagram: <a href="https://www.instagram.com/safegreen_certificado_digital" style="color:#15803d;font-weight:600;text-decoration:none;">@safegreen_certificado_digital</a> ·
                            Site: <a href="https://safegreen.com.br" style="color:#15803d;font-weight:600;text-decoration:none;">safegreen.com.br</a>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
HTML;
    }

    private function templateLoyalty(): string
    {
        return <<<HTML
<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="font-family:'Segoe UI',Arial,sans-serif;background:#fefce8;color:#0f172a;padding:0;margin:0;">
    <tr>
        <td align="center" style="padding:40px 14px;">
            <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="width:100%;max-width:560px;background:#ffffff;border-radius:22px;overflow:hidden;border:1px solid #c7f9cc;box-shadow:0 20px 48px -26px rgba(34,197,94,0.26);">
                <tr>
                    <td style="padding:0;height:9px;background:linear-gradient(120deg,#16a34a 0%,#22c55e 45%,#34d399 100%);"></td>
                </tr>
                <tr>
                    <td style="padding:28px 24px 14px 24px;">
                        <p style="margin:0;font-size:13px;letter-spacing:0.14em;text-transform:uppercase;color:#92400e;font-weight:700;">Programa fidelidade SafeGreen</p>
                        <h1 style="margin:10px 0 0;font-size:30px;font-weight:800;color:#0f172a;">Benefícios exclusivos para você</h1>
                        <p style="margin:16px 0 0;font-size:16px;line-height:1.7;color:#1f2937;">
                            {{client_name}}, obrigado por seguir com a gente. Preparamos vantagens para sua próxima emissão de certificado digital, seja para você ou para a empresa {{empresa}}.
                        </p>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 24px 18px 24px;">
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;">
                            <tr>
                                <td style="padding:22px 24px;border-radius:18px;background:linear-gradient(135deg,rgba(250,204,21,0.16),rgba(34,197,94,0.12));border:1px solid rgba(234,179,8,0.32);">
                                    <h2 style="margin:0 0 12px;font-size:19px;color:#0f172a;">Na sua próxima renovação você garante:</h2>
                                    <ul style="margin:0;padding-left:18px;color:#1f2937;font-size:15px;line-height:1.75;">
                                        <li>15% de desconto em certificados A1 + kit de renovação</li>
                                        <li>Canal rápido no WhatsApp para agendamentos e ajustes</li>
                                        <li>Bônus de orientação em assinatura digital para sua equipe</li>
                                    </ul>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
                <tr>
                    <td style="padding:0 24px 26px 24px;">
                        <table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="border-collapse:collapse;">
                            <tr>
                                <td style="padding:22px 24px;border-radius:16px;border:1px solid #fde68a;background:#fffbeb;">
                                    <p style="margin:0;font-size:15px;line-height:1.7;color:#1f2937;">
                                        Responda com o melhor horário ou clique abaixo. Ajustamos documento, titular e formato de atendimento conforme sua necessidade.
                                    </p>
                                    <div style="text-align:center;margin-top:16px;">
                                        <a href="https://wa.me/551936296525" style="display:inline-block;padding:14px 28px;border-radius:999px;background:linear-gradient(120deg,#facc15,#f97316);color:#0f172a;font-weight:700;text-decoration:none;font-size:15px;">Garantir meu benefício</a>
                                    </div>
                                </td>
                            </tr>
                        </table>
                        <p style="margin:18px 0 0;font-size:12px;color:#1f2937;">Oferta válida até {{certificate_expires_at_formatted}}. Para combos empresariais, avise e enviamos um plano sob medida.</p>
                        <p style="margin:14px 0 0;font-size:14px;color:#1f2937;font-weight:700;">SafeGreen Certificado Digital</p>
                        <p style="margin:8px 0 0;font-size:13px;color:#1f2937;">
                            Fone/Whatsapp (19)3629-6525<br>
                            instagram: <a href="https://www.instagram.com/safegreen_certificado_digital" style="color:#15803d;font-weight:600;text-decoration:none;">@safegreen_certificado_digital</a> ·
                            Site: <a href="https://safegreen.com.br" style="color:#15803d;font-weight:600;text-decoration:none;">safegreen.com.br</a>
                        </p>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
HTML;
    }
}
