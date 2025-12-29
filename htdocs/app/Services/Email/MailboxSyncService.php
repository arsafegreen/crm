<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Repositories\EmailAccountRepository;
use App\Repositories\Email\EmailAttachmentRepository;
use App\Repositories\Email\EmailFolderRepository;
use App\Repositories\Email\EmailMessageParticipantRepository;
use App\Repositories\Email\EmailMessageRepository;
use App\Repositories\Email\EmailThreadRepository;
use RuntimeException;

final class MailboxSyncService
{
    private EmailAccountRepository $accounts;
    private EmailFolderRepository $folders;
    private EmailMessageRepository $messages;
    private EmailThreadRepository $threads;
    private EmailMessageParticipantRepository $participants;
    private EmailAttachmentRepository $attachments;
    /** @var array<int, array<string, mixed>|null> */
    private array $folderCache = [];

    public function __construct(
        ?EmailAccountRepository $accounts = null,
        ?EmailFolderRepository $folders = null,
        ?EmailMessageRepository $messages = null,
        ?EmailThreadRepository $threads = null,
        ?EmailMessageParticipantRepository $participants = null,
        ?EmailAttachmentRepository $attachments = null
    ) {
        $this->accounts = $accounts ?? new EmailAccountRepository();
        $this->folders = $folders ?? new EmailFolderRepository();
        $this->messages = $messages ?? new EmailMessageRepository();
        $this->threads = $threads ?? new EmailThreadRepository();
        $this->participants = $participants ?? new EmailMessageParticipantRepository();
        $this->attachments = $attachments ?? new EmailAttachmentRepository();
    }

    /**
     * @param array{limit?: int, folders?: array<int, string>, force_resync?: bool, lookback_days?: int} $options
     * @return array<int, array<string, mixed>>
     */
    public function syncAll(array $options = []): array
    {
        $accounts = $this->accounts->all();
        $synced = [];

        foreach ($accounts as $account) {
            if ((int)($account['imap_sync_enabled'] ?? 0) !== 1) {
                continue;
            }

            $synced[] = $this->syncAccount((int)$account['id'], $options);
        }

        return $synced;
    }

    /**
     * @param array{limit?: int, folders?: array<int, string>, force_resync?: bool, lookback_days?: int} $options
     * @return array<string, mixed>
     */
    public function syncAccount(int $accountId, array $options = []): array
    {
        $account = $this->accounts->find($accountId);
        if ($account === null) {
            throw new RuntimeException('Conta IMAP não encontrada.');
        }

        if ((int)($account['imap_sync_enabled'] ?? 0) !== 1) {
            throw new RuntimeException('Sincronização IMAP está desabilitada para esta conta.');
        }

        if (!function_exists('imap_open')) {
            throw new RuntimeException('Extensão IMAP não está disponível no PHP.');
        }

        $this->folderCache = [];

        $preparedAccount = $this->hydrateAccount($account);
        $limit = max(1, (int)($options['limit'] ?? 100));
        $allowedFolders = isset($options['folders']) && is_array($options['folders'])
            ? array_map('strval', $options['folders'])
            : null;
        $forceResync = !empty($options['force_resync']);
        $lookbackDays = isset($options['lookback_days']) ? max(1, (int)$options['lookback_days']) : null;

        $mailboxBase = $this->mailboxBase($preparedAccount);
        $stream = $this->connect($mailboxBase, $preparedAccount);

        try {
            $mailboxes = $this->listFolders($stream, $mailboxBase, $preparedAccount);
            $stats = [
                'account_id' => $accountId,
                'folders' => [],
                'fetched' => 0,
                'skipped' => 0,
                'errors' => 0,
            ];

            foreach ($mailboxes as $remoteName => $display) {
                if ($allowedFolders !== null && !in_array($remoteName, $allowedFolders, true)) {
                    continue;
                }

                $folderRow = $this->folders->upsert($accountId, $remoteName, [
                    'display_name' => $display,
                    'type' => $this->detectFolderType($remoteName),
                ]);

                $result = $this->syncFolder($stream, $mailboxBase, $preparedAccount, $folderRow, $limit, $forceResync, $lookbackDays);
                $stats['folders'][] = $result;
                $stats['fetched'] += $result['fetched'];
                $stats['skipped'] += $result['skipped'];
                $stats['errors'] += $result['errors'];
            }

            $this->accounts->update($accountId, [
                'imap_last_sync_at' => time(),
                'imap_last_uid' => $this->maxUidFromStats($stats['folders']),
            ]);

            return $stats;
        } finally {
            imap_close($stream);
        }
    }

    private function connect(string $mailboxBase, array $account)
    {
        $mailbox = $mailboxBase . 'INBOX';
        $username = $account['imap_username'] ?? ($account['credentials']['username'] ?? null);
        $password = $account['imap_password'] ?? ($account['credentials']['password'] ?? null);

        if ($username === null || $password === null) {
            throw new RuntimeException('Credenciais IMAP não configuradas.');
        }

        $stream = @imap_open($mailbox, $username, $password, OP_READONLY, 1, [
            'DISABLE_AUTHENTICATOR' => 'GSSAPI',
        ]);

        if ($stream === false) {
            $errors = imap_errors();
            throw new RuntimeException('Falha ao conectar no IMAP: ' . implode('; ', $errors ?? ['desconhecido']));
        }

        return $stream;
    }

    /**
     * @return array<string, string>
     */
    private function listFolders($stream, string $mailboxBase, array $account): array
    {
        $mailboxes = imap_list($stream, $mailboxBase, '*');
        if ($mailboxes === false) {
            throw new RuntimeException('Não foi possível listar pastas IMAP.');
        }

        $list = [];
        foreach ($mailboxes as $mailbox) {
            $remote = str_replace($mailboxBase, '', $mailbox);
            $list[$remote] = $remote;
        }

        if ($list === []) {
            $list['INBOX'] = 'INBOX';
        }

        return $list;
    }

    /**
    * @param array<string, mixed> $account
    * @param array<string, mixed> $folder
    * @return array{folder: string, fetched: int, skipped: int, errors: int, last_uid: ?int}
     */
    private function syncFolder($stream, string $mailboxBase, array $account, array $folder, int $limit, bool $forceResync = false, ?int $lookbackDays = null): array
    {
        $remoteName = (string)$folder['remote_name'];
        $mailboxPath = $mailboxBase . $remoteName;
        if (!imap_reopen($stream, $mailboxPath, OP_READONLY)) {
            return [
                'folder' => $remoteName,
                'fetched' => 0,
                'skipped' => 0,
                'errors' => 1,
                'last_uid' => $folder['sync_token'] ?? null,
            ];
        }

        $status = imap_status($stream, $mailboxPath, SA_UNSEEN | SA_UIDNEXT);
        $lastToken = isset($folder['sync_token']) ? (int)$folder['sync_token'] : 0;

        $query = 'ALL';
        if ($lookbackDays !== null && $lookbackDays > 0) {
            $since = date('d-M-Y', time() - ($lookbackDays * 86400));
            $query = 'SINCE "' . $since . '"';
        }

        $uids = imap_search($stream, $query, SE_UID) ?: [];
        sort($uids);
        $pending = array_values(array_filter($uids, static fn($uid): bool => (int)$uid > $lastToken));

        if ($pending === []) {
            $this->folders->markSynced((int)$folder['id'], [
                'unread_count' => $status ? (int)$status->unseen : null,
                'last_synced_at' => time(),
            ]);

            return [
                'folder' => $remoteName,
                'fetched' => 0,
                'skipped' => 0,
                'errors' => 0,
                'last_uid' => $lastToken,
            ];
        }

        if (count($pending) > $limit) {
            $pending = array_slice($pending, -$limit);
        }

        $fetched = 0;
        $skipped = 0;
        $errors = 0;
        $lastProcessed = $lastToken;

        foreach ($pending as $uid) {
            $existing = $this->messages->findByExternalUid((int)$account['id'], (string)$uid);
            $shouldResync = $existing !== null && $forceResync;

            try {
                $message = $this->extractMessage($stream, (int)$uid);
                $threadId = $shouldResync && !empty($existing['thread_id'])
                    ? (int)$existing['thread_id']
                    : $this->resolveThreadId((int)$account['id'], $folder, $message);
                $snippet = $this->buildSnippet($message['body']);
                $paths = $this->persistBodies(
                    (int)$account['id'],
                    (int)$folder['id'],
                    (int)$uid,
                    $message['body'],
                    $message['body_is_html'] ?? false
                );
                $storedAttachments = $this->storeAttachments(
                    (int)$account['id'],
                    (int)$folder['id'],
                    (int)$uid,
                    $message['attachments']
                );

                if ($existing !== null && !$forceResync) {
                    $skipped++;
                    $lastProcessed = (int)$uid;
                    continue;
                }

                if ($existing !== null && $forceResync) {
                    $this->participants->replaceForMessage((int)$existing['id'], $this->participantsFromMessage($message));
                    $this->attachments->deleteByMessage((int)$existing['id']);
                    if ($storedAttachments !== []) {
                        $this->attachments->insertMany((int)$existing['id'], $storedAttachments);
                    }

                    $this->messages->update((int)$existing['id'], [
                        'thread_id' => $threadId,
                        'folder_id' => (int)$folder['id'],
                        'direction' => 'inbound',
                        'status' => 'received',
                        'subject' => $message['subject'],
                        'sender_name' => $message['from']['name'] ?? null,
                        'sender_email' => $message['from']['email'] ?? null,
                        'to_recipients' => $this->encodeJson($message['to']),
                        'cc_recipients' => $this->encodeJson($message['cc']),
                        'bcc_recipients' => $this->encodeJson($message['bcc']),
                        'internet_message_id' => $message['message_id'],
                        'in_reply_to' => $message['in_reply_to'],
                        'references_header' => $message['references'],
                        'sent_at' => $message['sent_at'],
                        'received_at' => time(),
                        'size_bytes' => $message['size'],
                        'body_text_path' => $paths['text'],
                        'body_html_path' => $paths['html'],
                        'headers' => $message['raw_headers'],
                        'metadata' => $this->encodeJson([
                            'flags' => $message['flags'],
                        ]),
                        'hash' => sha1((int)$account['id'] . ':' . $uid . ':' . ($message['message_id'] ?? '')),
                    ]);

                    $messageId = (int)$existing['id'];
                } else {
                    $messageId = $this->messages->insert([
                        'thread_id' => $threadId,
                        'account_id' => (int)$account['id'],
                        'folder_id' => (int)$folder['id'],
                        'direction' => 'inbound',
                        'status' => 'received',
                        'subject' => $message['subject'],
                        'sender_name' => $message['from']['name'] ?? null,
                        'sender_email' => $message['from']['email'] ?? null,
                        'to_recipients' => $this->encodeJson($message['to']),
                        'cc_recipients' => $this->encodeJson($message['cc']),
                        'bcc_recipients' => $this->encodeJson($message['bcc']),
                        'external_uid' => (string)$uid,
                        'internet_message_id' => $message['message_id'],
                        'in_reply_to' => $message['in_reply_to'],
                        'references_header' => $message['references'],
                        'sent_at' => $message['sent_at'],
                        'received_at' => time(),
                        'size_bytes' => $message['size'],
                        'body_text_path' => $paths['text'],
                        'body_html_path' => $paths['html'],
                        'headers' => $message['raw_headers'],
                        'metadata' => $this->encodeJson([
                            'flags' => $message['flags'],
                        ]),
                        'hash' => sha1((int)$account['id'] . ':' . $uid . ':' . ($message['message_id'] ?? '')),
                    ]);

                    $this->participants->replaceForMessage($messageId, $this->participantsFromMessage($message));
                    if ($storedAttachments !== []) {
                        $this->attachments->insertMany($messageId, $storedAttachments);
                    }
                }

                $threadRow = $this->threads->find($threadId);
                $touchPayload = [
                    'snippet' => $snippet,
                    'last_message_at' => $message['sent_at'],
                ];

                if ($existing === null && $this->shouldIncrementUnread($message['flags'])) {
                    $touchPayload['unread_increment'] = 1;
                }

                if ($this->shouldUpdateThreadFolder($threadRow, $folder)) {
                    $touchPayload['folder_id'] = (int)$folder['id'];
                }

                $this->threads->touch($threadId, $touchPayload);

                $fetched++;
                $lastProcessed = (int)$uid;
            } catch (RuntimeException $exception) {
                $errors++;
            }
        }

        $this->folders->markSynced((int)$folder['id'], [
            'sync_token' => $lastProcessed,
            'unread_count' => $status ? (int)$status->unseen : null,
            'last_synced_at' => time(),
        ]);

        return [
            'folder' => $remoteName,
            'fetched' => $fetched,
            'skipped' => $skipped,
            'errors' => $errors,
            'last_uid' => $lastProcessed,
        ];
    }

    private function extractMessage($stream, int $uid): array
    {
        $msgNo = imap_msgno($stream, $uid);
        if ($msgNo === 0) {
            throw new RuntimeException('Mensagem IMAP não encontrada.');
        }

        $header = imap_headerinfo($stream, $msgNo);
        $rawHeaders = imap_fetchheader($stream, $uid, FT_UID) ?: '';
        $structure = imap_fetchstructure($stream, $uid, FT_UID);
        $bodyPayload = $this->extractBodyContent($stream, (int)$uid, $structure);
        $body = $bodyPayload['body'];
        $attachments = $structure ? $this->gatherAttachments($stream, $uid, $structure) : [];

        return [
            'subject' => $this->decodeHeader($header->subject ?? ''),
            'from' => $this->firstAddress($header->from ?? []),
            'to' => $this->mapAddresses($header->to ?? []),
            'cc' => $this->mapAddresses($header->cc ?? []),
            'bcc' => $this->mapAddresses($header->bcc ?? []),
            'message_id' => isset($header->message_id) ? trim((string)$header->message_id) : null,
            'in_reply_to' => isset($header->in_reply_to) ? trim((string)$header->in_reply_to) : null,
            'references' => isset($header->references) ? trim((string)$header->references) : null,
            'sent_at' => isset($header->udate) ? (int)$header->udate : time(),
            'size' => isset($header->Size) ? (int)$header->Size : strlen($body),
            'raw_headers' => $rawHeaders,
            'body' => $body,
            'body_is_html' => (bool)($bodyPayload['is_html'] ?? false),
            'flags' => $this->collectFlags($stream, $msgNo),
            'attachments' => $attachments,
        ];
    }

    private function persistBodies(int $accountId, int $folderId, int $uid, string $body, bool $isHtml): array
    {
        if ($body === '') {
            return ['text' => null, 'html' => null];
        }

        $directory = storage_path('email' . DIRECTORY_SEPARATOR . 'messages' . DIRECTORY_SEPARATOR . $accountId);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $isHtml = $isHtml || stripos($body, '<html') !== false || stripos($body, '<body') !== false;
        $textPath = $directory . DIRECTORY_SEPARATOR . sprintf('%d_%d.txt', $folderId, $uid);
        file_put_contents($textPath, $isHtml ? strip_tags($body) : $body);

        $htmlPath = null;
        if ($isHtml) {
            $htmlPath = $directory . DIRECTORY_SEPARATOR . sprintf('%d_%d.html', $folderId, $uid);
            file_put_contents($htmlPath, $body);
        }

        return ['text' => $textPath, 'html' => $htmlPath];
    }

    private function collectFlags($stream, int $msgNo): array
    {
        $overview = imap_fetch_overview($stream, (string)$msgNo, 0) ?: [];
        if ($overview === []) {
            return [];
        }

        $flags = [];
        foreach ($overview as $entry) {
            foreach (['seen', 'flagged', 'answered', 'deleted', 'draft'] as $flag) {
                if (!empty($entry->{$flag})) {
                    $flags[] = $flag;
                }
            }
        }

        return array_values(array_unique($flags));
    }

    private function resolveThreadId(int $accountId, array $folder, array $message): int
    {
        $candidates = [];
        if (!empty($message['in_reply_to'])) {
            $candidates[] = (string)$message['in_reply_to'];
        }
        if (!empty($message['references'])) {
            $references = preg_split('/\s+/', (string)$message['references']) ?: [];
            foreach ($references as $reference) {
                $reference = trim($reference);
                if ($reference !== '') {
                    $candidates[] = $reference;
                }
            }
        }

        foreach ($candidates as $identifier) {
            $parent = $this->messages->findByInternetMessageId($accountId, $identifier);
            if ($parent !== null && !empty($parent['thread_id'])) {
                return (int)$parent['thread_id'];
            }
        }

        $subjectRaw = trim((string)($message['subject'] ?? ''));
        $subject = $subjectRaw !== '' ? $subjectRaw : '(sem assunto)';
        $normalized = $this->normalizeSubject($subject);

        $thread = $this->threads->findBySubject($accountId, $subject);
        if ($thread === null && $normalized !== $subject) {
            $thread = $this->threads->findBySubject($accountId, $normalized);
        }

        if ($thread !== null) {
            return (int)$thread['id'];
        }

        return $this->threads->create([
            'account_id' => $accountId,
            'folder_id' => (int)$folder['id'],
            'subject' => $subject,
            'snippet' => null,
            'primary_contact_id' => null,
            'primary_client_id' => null,
            'last_message_at' => $message['sent_at'] ?? time(),
            'unread_count' => 0,
            'flags' => null,
        ]);
    }

    private function participantsFromMessage(array $message): array
    {
        $participants = [];
        if (!empty($message['from']['email'])) {
            $participants[] = [
                'role' => 'from',
                'name' => $message['from']['name'] ?? null,
                'email' => $message['from']['email'],
            ];
        }

        foreach (['to', 'cc', 'bcc'] as $role) {
            foreach ($message[$role] ?? [] as $entry) {
                if (empty($entry['email'])) {
                    continue;
                }

                $participants[] = [
                    'role' => $role,
                    'name' => $entry['name'] ?? null,
                    'email' => $entry['email'],
                ];
            }
        }

        return $participants;
    }

    private function buildSnippet(string $body): string
    {
        if ($body === '') {
            return '';
        }

        $text = strip_tags($body);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/', ' ', $text ?? '') ?? '';
        $snippet = function_exists('mb_substr') ? mb_substr($text, 0, 180) : substr($text, 0, 180);

        return trim((string)$snippet);
    }

    private function normalizeSubject(string $subject): string
    {
        $normalized = trim($subject);
        if ($normalized === '') {
            return '(sem assunto)';
        }

        while (preg_match('/^(re|fw|fwd)\s*:/i', $normalized) === 1) {
            $normalized = trim((string)preg_replace('/^(re|fw|fwd)\s*:/i', '', $normalized, 1));
        }

        return $normalized === '' ? '(sem assunto)' : $normalized;
    }

    private function shouldIncrementUnread(array $flags): bool
    {
        $normalized = array_map(static fn($flag): string => strtolower((string)$flag), $flags);
        return !in_array('seen', $normalized, true);
    }

    private function storeAttachments(int $accountId, int $folderId, int $uid, array $attachments): array
    {
        if ($attachments === []) {
            return [];
        }

        $directory = storage_path('email' . DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR . $accountId);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $stored = [];
        foreach ($attachments as $index => $attachment) {
            $content = $attachment['content'] ?? null;
            $filename = trim((string)($attachment['filename'] ?? ''));
            if ($content === null || $content === '' || $filename === '') {
                continue;
            }

            $base = preg_replace('/[^A-Za-z0-9_-]/', '_', pathinfo($filename, PATHINFO_FILENAME));
            $base = $base !== '' ? $base : 'attachment';
            $extension = pathinfo($filename, PATHINFO_EXTENSION);
            $extension = $extension !== '' ? $extension : 'bin';
            $fileName = sprintf('%s_%d_%d_%d.%s', $base, $folderId, $uid, $index, $extension);
            $path = $directory . DIRECTORY_SEPARATOR . $fileName;

            file_put_contents($path, $content);
            $stored[] = [
                'filename' => $filename,
                'mime_type' => $attachment['mime_type'] ?? null,
                'size_bytes' => $attachment['size_bytes'] ?? strlen($content),
                'storage_path' => $path,
                'checksum' => sha1_file($path),
            ];
        }

        return $stored;
    }

    private function gatherAttachments($stream, int $uid, object $structure, string $prefix = ''): array
    {
        $attachments = [];
        if (isset($structure->parts) && is_array($structure->parts) && $structure->parts !== []) {
            foreach ($structure->parts as $index => $part) {
                $partNumber = $prefix === '' ? (string)($index + 1) : $prefix . '.' . ($index + 1);
                $attachments = array_merge(
                    $attachments,
                    $this->gatherAttachments($stream, $uid, $part, $partNumber)
                );
            }

            return $attachments;
        }

        $filename = null;
        foreach (['dparameters', 'parameters'] as $property) {
            if (!isset($structure->{$property}) || !is_array($structure->{$property})) {
                continue;
            }

            foreach ($structure->{$property} as $parameter) {
                if (isset($parameter->attribute) && strtolower($parameter->attribute) === 'filename') {
                    $filename = $parameter->value ?? null;
                    break 2;
                }
                if (isset($parameter->attribute) && strtolower($parameter->attribute) === 'name') {
                    $filename = $parameter->value ?? null;
                    break 2;
                }
            }
        }

        if ($filename === null) {
            return [];
        }

        $partNumber = $prefix === '' ? '1' : $prefix;
        $data = imap_fetchbody($stream, $uid, $partNumber, FT_UID);
        if ($data === false) {
            return [];
        }

        $decoded = $this->decodePartBody($data, (int)($structure->encoding ?? 0));

        return [[
            'filename' => $this->decodeHeader((string)$filename),
            'mime_type' => $this->resolveMimeType($structure),
            'size_bytes' => strlen($decoded),
            'content' => $decoded,
        ]];
    }

    /**
     * Attempts to extract the best message body from a MIME structure, preferring HTML over plain text.
     * Returns the decoded body and whether it came from an HTML part.
     *
     * @return array{body: string, is_html: bool}
     */
    private function extractBodyContent($stream, int $uid, ?object $structure): array
    {
        if ($structure === null) {
            $fallback = imap_body($stream, $uid, FT_UID | FT_PEEK) ?: '';
            return ['body' => $fallback, 'is_html' => false];
        }

        $parts = $this->collectTextParts($stream, $uid, $structure);
        if ($parts['html'] !== null) {
            return ['body' => $parts['html'], 'is_html' => true];
        }
        if ($parts['text'] !== null) {
            return ['body' => $parts['text'], 'is_html' => false];
        }

        $fallback = imap_body($stream, $uid, FT_UID | FT_PEEK) ?: '';
        return ['body' => $fallback, 'is_html' => false];
    }

    /**
     * Recursively collects the first HTML and plain-text parts from a MIME tree.
     *
     * @return array{html: ?string, text: ?string}
     */
    private function collectTextParts($stream, int $uid, object $structure, string $prefix = ''): array
    {
        $result = ['html' => null, 'text' => null];

        $hasChildren = isset($structure->parts) && is_array($structure->parts) && $structure->parts !== [];
        if ($hasChildren) {
            foreach ($structure->parts as $index => $part) {
                $partNumber = $prefix === '' ? (string)($index + 1) : $prefix . '.' . ($index + 1);
                $child = $this->collectTextParts($stream, $uid, $part, $partNumber);

                if ($result['html'] === null && $child['html'] !== null) {
                    $result['html'] = $child['html'];
                }
                if ($result['text'] === null && $child['text'] !== null) {
                    $result['text'] = $child['text'];
                }

                if ($result['html'] !== null && $result['text'] !== null) {
                    break;
                }
            }

            return $result;
        }

        $mime = $this->resolveMimeType($structure);
        if (strpos($mime, 'text/') !== 0) {
            return $result;
        }

        $partNumber = $prefix === '' ? '1' : $prefix;
        $data = $prefix === ''
            ? imap_body($stream, $uid, FT_UID | FT_PEEK)
            : imap_fetchbody($stream, $uid, $partNumber, FT_UID);

        if ($data === false) {
            return $result;
        }

        $decoded = $this->decodePartBody($data, (int)($structure->encoding ?? 0));
        $charset = $this->extractCharset($structure);
        $normalized = $this->convertToUtf8($decoded, $charset);

        if ($result['text'] === null && $mime === 'text/plain') {
            $result['text'] = $normalized;
        }

        if ($result['html'] === null && $mime === 'text/html') {
            $result['html'] = $normalized;
        }

        if ($result['text'] === null && $result['html'] === null) {
            // Any other text/* part falls back to plain text bucket.
            $result['text'] = $normalized;
        }

        return $result;
    }

    private function extractCharset(object $structure): ?string
    {
        foreach (['parameters', 'dparameters'] as $property) {
            if (!isset($structure->{$property}) || !is_array($structure->{$property})) {
                continue;
            }

            foreach ($structure->{$property} as $parameter) {
                if (isset($parameter->attribute) && strtolower((string)$parameter->attribute) === 'charset') {
                    $value = (string)($parameter->value ?? '');
                    return $value !== '' ? $value : null;
                }
            }
        }

        return null;
    }

    private function convertToUtf8(string $value, ?string $charset): string
    {
        if ($charset === null || $charset === '' || strtolower($charset) === 'utf-8') {
            return $value;
        }

        $charset = strtoupper($charset);

        if (function_exists('mb_convert_encoding')) {
            $converted = @mb_convert_encoding($value, 'UTF-8', $charset);
            if ($converted !== false) {
                return $converted;
            }
        }

        if (function_exists('iconv')) {
            $converted = @iconv($charset, 'UTF-8//TRANSLIT', $value);
            if ($converted !== false) {
                return $converted;
            }
        }

        return $value;
    }

    private function decodePartBody(string $data, int $encoding): string
    {
        return match ($encoding) {
            3 => base64_decode($data, true) ?: '',
            4 => quoted_printable_decode($data),
            default => $data,
        };
    }

    private function resolveMimeType(object $structure): string
    {
        $primary = match ((int)($structure->type ?? 0)) {
            0 => 'text',
            1 => 'multipart',
            2 => 'message',
            3 => 'application',
            4 => 'audio',
            5 => 'image',
            6 => 'video',
            default => 'application',
        };

        $subtype = isset($structure->subtype) ? strtolower((string)$structure->subtype) : 'octet-stream';
        return strtolower($primary . '/' . $subtype);
    }

    private function decodeHeader(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        $segments = imap_mime_header_decode($value);
        if ($segments === false) {
            return trim($value);
        }

        $decoded = '';
        foreach ($segments as $segment) {
            $decoded .= $segment->text;
        }

        return trim($decoded);
    }

    private function firstAddress(array $list): array
    {
        $addresses = $this->mapAddresses($list);
        return $addresses[0] ?? ['name' => null, 'email' => null];
    }

    private function mapAddresses(array $list): array
    {
        $result = [];
        foreach ($list as $item) {
            $email = isset($item->mailbox, $item->host)
                ? strtolower(trim($item->mailbox . '@' . $item->host))
                : null;

            if ($email === null || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            $result[] = [
                'name' => isset($item->personal) ? $this->decodeHeader((string)$item->personal) : null,
                'email' => $email,
            ];
        }

        return $result;
    }

    private function detectFolderType(string $remoteName): string
    {
        $upper = strtoupper($remoteName);
        if ($upper === 'INBOX') {
            return 'inbox';
        }
        if (str_contains($upper, 'SENT') || str_contains($upper, 'ENVIAD')) {
            return 'sent';
        }
        if (str_contains($upper, 'TRASH') || str_contains($upper, 'LIXEIRA')) {
            return 'trash';
        }
        if (str_contains($upper, 'DRAFT') || str_contains($upper, 'RASCUNH')) {
            return 'drafts';
        }
        if (str_contains($upper, 'SPAM') || str_contains($upper, 'JUNK')) {
            return 'spam';
        }
        if (str_contains($upper, 'ALL MAIL') || str_contains($upper, 'ALLMAIL')
            || str_contains($upper, 'ARQUIVO') || str_contains($upper, 'TODOS OS E-MAILS')
            || str_contains($upper, 'TODOS OS EMAILS')) {
            return 'archive';
        }
        if (str_contains($upper, 'IMPORTANT') || str_contains($upper, 'IMPORTANTE')) {
            return 'important';
        }
        if (str_contains($upper, 'STARRED') || str_contains($upper, 'ESTRELA')) {
            return 'starred';
        }

        return 'custom';
    }

    private function shouldUpdateThreadFolder(?array $thread, array $folder): bool
    {
        $targetId = (int)$folder['id'];

        if ($thread === null) {
            return true;
        }

        $currentId = isset($thread['folder_id']) ? (int)$thread['folder_id'] : 0;
        if ($currentId === 0) {
            return true;
        }

        if ($currentId === $targetId) {
            return false;
        }

        $current = $this->folderById($currentId);
        if ($current === null) {
            return true;
        }

        $currentPriority = $this->folderPriority($current['type'] ?? null);
        $incomingPriority = $this->folderPriority($folder['type'] ?? null);

        return $incomingPriority >= $currentPriority;
    }

    private function folderPriority(?string $type): int
    {
        return match (strtolower((string)$type)) {
            'trash', 'deleted' => 130,
            'spam', 'junk' => 120,
            'inbox' => 100,
            'important' => 95,
            'starred' => 90,
            'sent' => 80,
            'drafts' => 70,
            'archive' => 60,
            default => 50,
        };
    }

    private function folderById(int $id): ?array
    {
        if (!array_key_exists($id, $this->folderCache)) {
            $this->folderCache[$id] = $this->folders->find($id);
        }

        return $this->folderCache[$id];
    }

    private function mailboxBase(array $account): string
    {
        $flags = '/imap/notls';
        $encryption = strtolower((string)($account['imap_encryption'] ?? 'ssl'));
        if ($encryption === 'ssl') {
            $flags = '/imap/ssl/novalidate-cert';
        } elseif ($encryption === 'tls') {
            $flags = '/imap/tls/novalidate-cert';
        }

        $port = (int)($account['imap_port'] ?? 993);
        $host = trim((string)($account['imap_host'] ?? $account['smtp_host'] ?? 'localhost'));

        return sprintf('{%s:%d%s}', $host, $port, $flags);
    }

    private function hydrateAccount(array $row): array
    {
        $row['credentials'] = $this->decodeJson($row['credentials'] ?? null);
        return $row;
    }

    private function decodeJson(?string $payload): array
    {
        if ($payload === null || trim($payload) === '') {
            return [];
        }

        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function encodeJson(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        $options = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;
        $encoded = json_encode($payload, $options);

        if ($encoded === false) {
            return null;
        }

        return $encoded;
    }

    private function maxUidFromStats(array $folderStats): ?int
    {
        $uids = array_map(static fn(array $row): ?int => isset($row['last_uid']) ? (int)$row['last_uid'] : null, $folderStats);
        $uids = array_filter($uids, static fn($value): bool => $value !== null);
        if ($uids === []) {
            return null;
        }

        return max($uids);
    }
}
