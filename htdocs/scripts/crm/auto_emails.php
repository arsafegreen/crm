<?php

declare(strict_types=1);

use App\Database\Connection;
use App\Repositories\ClientRepository;
use App\Repositories\ClientActionMarkRepository;
use App\Repositories\TemplateRepository;
use App\Repositories\EmailAccountRepository;
use App\Services\CampaignAutomationConfig;
use App\Services\Mail\MimeMessageBuilder;
use App\Services\Mail\SmtpMailer;
use App\Support\Crypto;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

require __DIR__ . '/../../vendor/autoload.php';
require __DIR__ . '/../../app/Support/helpers.php';
require __DIR__ . '/../../bootstrap/app.php';

$options = parseOptions($argv);
$type = strtolower($options['type'] ?? 'renewal'); // renewal|birthday|all
$horizonDays = isset($options['horizon_days']) ? max(1, (int)$options['horizon_days']) : 30;
$markTtl = isset($options['mark_ttl_hours']) ? max(1, (int)$options['mark_ttl_hours']) * 3600 : 24 * 3600;
$limit = isset($options['limit']) ? max(1, (int)$options['limit']) : 500;

$pdo = Connection::instance();
$columns = listColumns($pdo, 'clients');

$templateRepository = new TemplateRepository();
$automationConfig = (new CampaignAutomationConfig())->load();
$renewalTemplate = loadDbTemplate($templateRepository, $automationConfig['renewal']['template_id'] ?? null);
$birthdayTemplate = loadDbTemplate($templateRepository, $automationConfig['birthday']['template_id'] ?? null);

$timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));

$senderAccountId = null;
if ($type === 'renewal' || $type === 'all') {
    $senderAccountId = $automationConfig['renewal']['sender_account_id'] ?? null;
} elseif ($type === 'birthday') {
    $senderAccountId = $automationConfig['birthday']['sender_account_id'] ?? null;
}

$mailerConfig = buildMailerConfig($senderAccountId);
$mailer = new SmtpMailer($mailerConfig['smtp']);
$fromEmail = $mailerConfig['from_email'];
$fromName = $mailerConfig['from_name'];
$replyTo = $mailerConfig['reply_to'];

$templates = loadTemplates();

$clientRepo = new ClientRepository();
$markRepo = new ClientActionMarkRepository();

$totalSent = 0;
$totalSkipped = 0;
$totalErrors = 0;

if ($type === 'renewal' || $type === 'all') {
    if (!in_array('last_certificate_expires_at', $columns, true)) {
        fwrite(STDERR, "[WARN] Coluna last_certificate_expires_at não encontrada; pulando renovação.\n");
    } else {
        [$sent, $skipped, $errors] = sendRenewals(
            $pdo,
            $clientRepo,
            $markRepo,
            $mailer,
            $templates,
            $renewalTemplate,
            $automationConfig['renewal'] ?? [],
            $horizonDays,
            $markTtl,
            $limit,
            $timezone,
            $fromEmail,
            $fromName,
            $replyTo
        );
        $totalSent += $sent; $totalSkipped += $skipped; $totalErrors += $errors;
    }
}

if ($type === 'birthday' || $type === 'all') {
    $birthColumn = null;
    foreach (['birthdate', 'titular_birthdate'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $birthColumn = $candidate;
            break;
        }
    }
    if ($birthColumn === null) {
        fwrite(STDERR, "[WARN] Nenhuma coluna de aniversário encontrada (birthdate/titular_birthdate); pulando aniversário.\n");
    } else {
        [$sent, $skipped, $errors] = sendBirthdays(
            $pdo,
            $clientRepo,
            $markRepo,
            $mailer,
            $templates,
            $birthdayTemplate,
            $automationConfig['birthday'] ?? [],
            $birthColumn,
            $markTtl,
            $limit,
            $timezone,
            $fromEmail,
            $fromName,
            $replyTo
        );
        $totalSent += $sent; $totalSkipped += $skipped; $totalErrors += $errors;
    }
}

fwrite(STDOUT, sprintf("Enviados: %d | Ignorados: %d | Erros: %d\n", $totalSent, $totalSkipped, $totalErrors));

// ------------------ Helpers ------------------

function sendRenewals(PDO $pdo, ClientRepository $repo, ClientActionMarkRepository $markRepo, SmtpMailer $mailer, array $defaultTemplates, ?array $dbTemplate, array $automation, int $horizonDays, int $markTtl, int $limit, DateTimeZone $timezone, string $fromEmail, string $fromName, ?string $replyTo): array
{
    $offsets = normalizeOffsets($automation['offsets'] ?? []);
    $useOffsets = ($automation['enabled'] ?? true) && $offsets !== [];

    if (!$useOffsets) {
        return sendRenewalsHorizon($pdo, $markRepo, $mailer, $defaultTemplates, $dbTemplate, $horizonDays, $markTtl, $limit, $fromEmail, $fromName, $replyTo);
    }

    $markTtl = max($markTtl, 86400 * 120);
    $today = new DateTimeImmutable('today', $timezone);

    $sent = $skipped = $errors = 0;
    foreach ($offsets as $offset) {
        if ($limit > 0 && $sent >= $limit) {
            break;
        }

        $target = $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
        $start = $target->setTime(0, 0, 0)->getTimestamp();
        $end = $target->setTime(23, 59, 59)->getTimestamp();
        $remaining = max(1, $limit - $sent);

        $rows = fetchRenewalCandidates($pdo, $start, $end, $remaining);
        if ($rows === []) {
            continue;
        }

        $marks = $markRepo->activeMarksForClients(array_column($rows, 'id'));
        $markType = 'renewal_notice_d' . $offset;

        foreach ($rows as $row) {
            $clientId = (int)($row['id'] ?? 0);
            $email = trim((string)($row['email'] ?? ''));
            $expires = (int)($row['last_certificate_expires_at'] ?? 0);
            $status = (string)($row['status'] ?? '');
            if ($clientId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }
            if (hasMark($marks, $clientId, $markType)) {
                $skipped++;
                continue;
            }

            $name = trim((string)($row['name'] ?? 'Cliente'));
            $expireLabel = $expires > 0 ? date('d/m/Y', $expires) : 'breve';
            $replacements = buildReplacements($name, $expireLabel, $status, $offset);
            [$subject, $bodyHtml, $bodyText] = buildEmailContent($dbTemplate, $defaultTemplates['renewal'] ?? [], $replacements);

            try {
                $raw = MimeMessageBuilder::build([
                    'from_email' => $fromEmail,
                    'from_name' => $fromName,
                    'reply_to' => $replyTo,
                    'to_list' => [['email' => $email, 'name' => $name]],
                    'subject' => $subject,
                    'body_html' => $bodyHtml,
                    'body_text' => $bodyText,
                ]);

                $mailer->send([
                    'from' => $fromEmail,
                    'recipients' => [$email],
                    'data' => $raw,
                ]);

                $markRepo->upsert($clientId, $markType, 0, $markTtl);
                $sent++;
            } catch (Throwable $e) {
                $errors++;
                @error_log('Renewal send error client ' . $clientId . ': ' . $e->getMessage());
            }
        }
    }

    return [$sent, $skipped, $errors];
}

function sendBirthdays(PDO $pdo, ClientRepository $repo, ClientActionMarkRepository $markRepo, SmtpMailer $mailer, array $defaultTemplates, ?array $dbTemplate, array $automation, string $birthColumn, int $markTtl, int $limit, DateTimeZone $timezone, string $fromEmail, string $fromName, ?string $replyTo): array
{
    $offsets = normalizeOffsets($automation['offsets'] ?? [0]);
    $useOffsets = ($automation['enabled'] ?? true) && $offsets !== [];

    if (!$useOffsets) {
        return [0, 0, 0];
    }

    $markTtl = max($markTtl, 86400 * 370);
    $today = new DateTimeImmutable('today', $timezone);

    $sent = $skipped = $errors = 0;
    foreach ($offsets as $offset) {
        if ($limit > 0 && $sent >= $limit) {
            break;
        }

        $target = $today->modify(($offset >= 0 ? '+' : '') . $offset . ' days');
        $md = $target->format('m-d');
        $remaining = max(1, $limit - $sent);

                $stmt = $pdo->prepare(
                        sprintf(
                                'SELECT id, name, email, document, %s AS birth_ts
                                     FROM clients
                                    WHERE is_off = 0
                                        AND email IS NOT NULL
                                        AND %s IS NOT NULL
                                        AND strftime("%%m-%%d", datetime(%s, "unixepoch")) = :md
                                    LIMIT :lim',
                                $birthColumn,
                                $birthColumn,
                                $birthColumn
                        )
                );
        $stmt->bindValue(':md', $md, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $remaining, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        if ($rows === []) {
            continue;
        }

        $marks = $markRepo->activeMarksForClients(array_column($rows, 'id'));
        $markType = 'birthday_notice_doc';
        $docCache = [];
        $docMarkCache = [];

        foreach ($rows as $row) {
            $clientId = (int)($row['id'] ?? 0);
            $email = trim((string)($row['email'] ?? ''));
            $name = trim((string)($row['name'] ?? 'Cliente'));
            $document = digits_only((string)($row['document'] ?? ''));
            if ($clientId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;
                continue;
            }
            if ($document !== '' && isset($docMarkCache[$document]) && $docMarkCache[$document] === true) {
                $skipped++;
                continue;
            }
            if (hasMark($marks, $clientId, $markType)) {
                $skipped++;
                continue;
            }

            $docIds = $document !== '' ? loadClientIdsByDocument($pdo, $document, $docCache) : [$clientId];
            $docMarks = $markRepo->activeMarksForClients($docIds);
            if ($document !== '' && documentHasMark($docMarks, $markType)) {
                $docMarkCache[$document] = true;
                $skipped++;
                continue;
            }

            $replacements = buildReplacements($name, $target->format('d/m'), '', $offset);
            [$subject, $bodyHtml, $bodyText] = buildEmailContent($dbTemplate, $defaultTemplates['birthday'] ?? [], $replacements);

            try {
                $raw = MimeMessageBuilder::build([
                    'from_email' => $fromEmail,
                    'from_name' => $fromName,
                    'reply_to' => $replyTo,
                    'to_list' => [['email' => $email, 'name' => $name]],
                    'subject' => $subject,
                    'body_html' => $bodyHtml,
                    'body_text' => $bodyText,
                ]);

                $mailer->send([
                    'from' => $fromEmail,
                    'recipients' => [$email],
                    'data' => $raw,
                ]);

                foreach ($docIds as $id) {
                    $markRepo->upsert($id, $markType, 0, $markTtl);
                }
                if ($document !== '') {
                    $docMarkCache[$document] = true;
                }
                $sent++;
            } catch (Throwable $e) {
                $errors++;
                @error_log('Birthday send error client ' . $clientId . ': ' . $e->getMessage());
            }
        }
    }

    return [$sent, $skipped, $errors];
}

function parseOptions(array $argv): array
{
    $options = [];
    foreach ($argv as $arg) {
        if (!str_starts_with($arg, '--')) {
            continue;
        }
        $parts = explode('=', substr($arg, 2), 2);
        if (count($parts) === 2) {
            $options[str_replace('-', '_', $parts[0])] = $parts[1];
        }
    }
    return $options;
}

function sendRenewalsHorizon(PDO $pdo, ClientActionMarkRepository $markRepo, SmtpMailer $mailer, array $defaultTemplates, ?array $dbTemplate, int $horizonDays, int $markTtl, int $limit, string $fromEmail = null, string $fromName = null, ?string $replyTo = null): array
{
    $now = time();
    $stmt = $pdo->prepare(
        'SELECT id, name, email, last_certificate_expires_at, status
           FROM clients
          WHERE is_off = 0
            AND status IN ("active", "recent_expired")
            AND email IS NOT NULL
            AND last_certificate_expires_at BETWEEN :today AND :limit
          ORDER BY last_certificate_expires_at ASC
          LIMIT :lim'
    );
    $stmt->bindValue(':today', $now, PDO::PARAM_INT);
    $stmt->bindValue(':limit', $now + ($horizonDays * 86400), PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $marks = $markRepo->activeMarksForClients(array_column($rows, 'id'));
    $markTtl = max($markTtl, 86400 * 120);

    $sent = $skipped = $errors = 0;
    foreach ($rows as $row) {
        $clientId = (int)($row['id'] ?? 0);
        $email = trim((string)($row['email'] ?? ''));
        $expires = (int)($row['last_certificate_expires_at'] ?? 0);
        $status = (string)($row['status'] ?? '');
        if ($clientId <= 0 || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $skipped++;
            continue;
        }
        if (hasMark($marks, $clientId, 'renewal_notice')) {
            $skipped++;
            continue;
        }

        $name = trim((string)($row['name'] ?? 'Cliente'));
        $expireLabel = $expires > 0 ? date('d/m/Y', $expires) : 'breve';
        $replacements = buildReplacements($name, $expireLabel, $status, 0);
        [$subject, $bodyHtml, $bodyText] = buildEmailContent($dbTemplate, $defaultTemplates['renewal'] ?? [], $replacements);

        try {
            $raw = MimeMessageBuilder::build([
                'from_email' => $fromEmail ?? env('SMTP_FROM_EMAIL', 'contato@safegreen.com.br'),
                'from_name' => $fromName ?? env('SMTP_FROM_NAME', 'Safegreen'),
                'reply_to' => $replyTo,
                'to_list' => [['email' => $email, 'name' => $name]],
                'subject' => $subject,
                'body_html' => $bodyHtml,
                'body_text' => $bodyText,
            ]);

            $mailer->send([
                'from' => $fromEmail ?? env('SMTP_FROM_EMAIL', 'contato@safegreen.com.br'),
                'recipients' => [$email],
                'data' => $raw,
            ]);

            $markRepo->upsert($clientId, 'renewal_notice', 0, $markTtl);
            $sent++;
        } catch (Throwable $e) {
            $errors++;
            @error_log('Renewal send error client ' . $clientId . ': ' . $e->getMessage());
        }
    }

    return [$sent, $skipped, $errors];
}

function fetchRenewalCandidates(PDO $pdo, int $start, int $end, int $limit): array
{
    $stmt = $pdo->prepare(
        'SELECT id, name, email, last_certificate_expires_at, status
           FROM clients
          WHERE is_off = 0
            AND status IN ("active", "recent_expired")
            AND email IS NOT NULL
            AND last_certificate_expires_at BETWEEN :start AND :end
          ORDER BY last_certificate_expires_at ASC
          LIMIT :lim'
    );
    $stmt->bindValue(':start', $start, PDO::PARAM_INT);
    $stmt->bindValue(':end', $end, PDO::PARAM_INT);
    $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function normalizeOffsets(array $offsets): array
{
    $result = [];
    foreach ($offsets as $offset) {
        $int = (int)$offset;
        $result[$int] = $int;
    }

    return array_values($result);
}

function hasMark(array $marks, int $clientId, string $type): bool
{
    foreach ($marks[$clientId] ?? [] as $mark) {
        if (($mark['type'] ?? '') === $type) {
            return true;
        }
    }

    return false;
}

function buildReplacements(string $name, string $dateLabel, string $status, int $offset): array
{
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');

    return [
        '{name}' => $safeName,
        '{date}' => $dateLabel,
        '{{client_name}}' => $safeName,
        '{{certificate_expires_at_formatted}}' => $dateLabel,
        '{{certificate_expires_at}}' => $dateLabel,
        '{{client_status}}' => $status,
        '{{offset_days}}' => (string)$offset,
    ];
}

function buildEmailContent(?array $dbTemplate, array $defaultTemplate, array $replacements): array
{
    $subject = trim((string)($dbTemplate['subject'] ?? $defaultTemplate['subject'] ?? ''));
    $html = (string)($dbTemplate['body_html'] ?? $dbTemplate['body_text'] ?? $defaultTemplate['html'] ?? '');
    $text = (string)($dbTemplate['body_text'] ?? $defaultTemplate['text'] ?? '');

    if ($text === '') {
        $text = strip_tags(str_replace('<br>', "\n", $html));
    }

    $subject = strtr($subject, $replacements);
    $html = strtr($html, $replacements);
    $text = strtr($text, $replacements);

    return [$subject, $html, $text];
}

function loadClientIdsByDocument(PDO $pdo, string $document, array &$cache): array
{
    if (isset($cache[$document])) {
        return $cache[$document];
    }

    $stmt = $pdo->prepare('SELECT id FROM clients WHERE document = :doc AND is_off = 0');
    $stmt->execute([':doc' => $document]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $ids = array_values(array_filter(array_map(static fn($row) => isset($row['id']) ? (int)$row['id'] : 0, $rows), static fn(int $v) => $v > 0));
    $cache[$document] = $ids === [] ? [] : $ids;
    return $cache[$document];
}

function documentHasMark(array $marksByClient, string $markType): bool
{
    foreach ($marksByClient as $clientMarks) {
        foreach ($clientMarks as $mark) {
            if (($mark['type'] ?? '') === $markType) {
                return true;
            }
        }
    }
    return false;
}

function buildMailerConfig(?int $accountId): array
{
    $repo = new EmailAccountRepository();
    $account = null;

    if ($accountId !== null && $accountId > 0) {
        $account = $repo->findActiveSender($accountId);
    }
    if ($account === null) {
        $account = $repo->findActiveSender();
    }

    $fromEmail = env('SMTP_FROM_EMAIL', 'contato@safegreen.com.br');
    $fromName = env('SMTP_FROM_NAME', 'Safegreen');
    $replyTo = null;
    $smtp = [
        'host' => env('SMTP_HOST', 'server18.mailgrid.com.br'),
        'port' => (int)env('SMTP_PORT', 587),
        'encryption' => env('SMTP_ENCRYPTION', 'tls'),
        'auth_mode' => 'login',
        'username' => env('SMTP_USERNAME'),
        'password' => env('SMTP_PASSWORD'),
    ];

    if ($account !== null) {
        $fromEmail = (string)($account['from_email'] ?? $fromEmail);
        $fromName = trim((string)($account['from_name'] ?? $fromName)) ?: $fromName;
        $replyTo = $account['reply_to'] ?? null;

        $creds = json_decode((string)($account['credentials'] ?? ''), true) ?? [];
        $password = decryptSecret($creds['password'] ?? null);
        $username = $creds['username'] ?? null;

        $smtp = [
            'host' => $account['smtp_host'] ?? $smtp['host'],
            'port' => isset($account['smtp_port']) ? (int)$account['smtp_port'] : $smtp['port'],
            'encryption' => $account['encryption'] ?? $smtp['encryption'],
            'auth_mode' => $account['auth_mode'] ?? $smtp['auth_mode'],
            'username' => $username ?: $fromEmail,
            'password' => $password,
        ];
    }

    return [
        'smtp' => $smtp,
        'from_email' => $fromEmail,
        'from_name' => $fromName,
        'reply_to' => $replyTo,
    ];
}

function decryptSecret(mixed $value): ?string
{
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    if (!str_starts_with($value, 'enc:')) {
        return $value;
    }

    $payload = substr($value, 4);
    try {
        return Crypto::decrypt($payload);
    } catch (Throwable) {
        return null;
    }
}

function loadDbTemplate(TemplateRepository $repository, ?int $templateId): ?array
{
    if ($templateId === null || $templateId <= 0) {
        return null;
    }

    $template = $repository->find($templateId);
    if ($template === null) {
        return null;
    }

    return [
        'subject' => $template['subject'] ?? null,
        'body_html' => $template['body_html'] ?? null,
        'body_text' => $template['body_text'] ?? null,
    ];
}

function loadTemplates(): array
{
    $default = [
        'renewal' => [
            'subject' => 'Aviso de renovação do certificado digital',
            'html' => 'Olá {name},<br><br>Seu certificado digital vence em {date}. Responda este e-mail para renovar sem interrupções.<br><br>Atenciosamente,<br>Equipe Safegreen',
            'text' => 'Olá {name},\n\nSeu certificado digital vence em {date}. Responda este e-mail para renovar sem interrupções.\n\nAtenciosamente,\nEquipe Safegreen',
        ],
        'birthday' => [
            'subject' => 'Feliz aniversário!',
            'html' => 'Olá {name},<br><br>Feliz aniversário! Que seu dia seja incrível. Conte sempre com a equipe Safegreen.<br><br>Um abraço!',
            'text' => 'Olá {name},\n\nFeliz aniversário! Que seu dia seja incrível. Conte sempre com a equipe Safegreen.\n\nUm abraço!',
        ],
    ];

    $customPath = __DIR__ . DIRECTORY_SEPARATOR . 'templates.php';
    if (is_file($customPath)) {
        $loaded = include $customPath;
        if (is_array($loaded)) {
            return array_replace_recursive($default, $loaded);
        }
    }

    return $default;
}

function listColumns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare('PRAGMA table_info(' . $table . ')');
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_map(static fn($row) => (string)$row['name'], $rows);
}
