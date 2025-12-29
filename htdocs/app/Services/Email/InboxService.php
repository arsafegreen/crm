<?php

declare(strict_types=1);

namespace App\Services\Email;

use App\Repositories\EmailAccountRepository;
use App\Repositories\Email\EmailAttachmentRepository;
use App\Repositories\Email\EmailFolderRepository;
use App\Repositories\Email\EmailMessageParticipantRepository;
use App\Repositories\Marketing\MarketingContactRepository;
use App\Repositories\ClientRepository;
use App\Repositories\Email\EmailMessageRepository;
use App\Repositories\Email\EmailThreadRepository;
use DateTimeImmutable;
use RuntimeException;
use App\Services\Mail\MimeMessageBuilder;
use App\Services\Mail\SmtpMailer;
use App\Support\Crypto;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class InboxService
{
    private EmailAccountRepository $accounts;
    private EmailFolderRepository $folders;
    private EmailThreadRepository $threads;
    private EmailMessageRepository $messages;
    private EmailMessageParticipantRepository $participants;
    private EmailAttachmentRepository $attachments;

    public function __construct(
        ?EmailAccountRepository $accounts = null,
        ?EmailFolderRepository $folders = null,
        ?EmailThreadRepository $threads = null,
        ?EmailMessageRepository $messages = null,
        ?EmailMessageParticipantRepository $participants = null,
        ?EmailAttachmentRepository $attachments = null
    ) {
        $this->accounts = $accounts ?? new EmailAccountRepository();
        $this->folders = $folders ?? new EmailFolderRepository();
        $this->threads = $threads ?? new EmailThreadRepository();
        $this->messages = $messages ?? new EmailMessageRepository();
        $this->participants = $participants ?? new EmailMessageParticipantRepository();
        $this->attachments = $attachments ?? new EmailAttachmentRepository();
    }

    public function listAccounts(): array
    {
        $accounts = $this->accounts->all();

        return array_values(array_filter($accounts, static function (array $account): bool {
            if (isset($account['deleted_at']) && $account['deleted_at'] !== null) {
                return false;
            }

            return ($account['status'] ?? 'inactive') === 'active';
        }));
    }

    public function resolveAccount(?int $accountId = null): array
    {
        if ($accountId !== null) {
            $account = $this->accounts->find($accountId);
            if ($account !== null) {
                return $account;
            }
        }

        $accounts = $this->listAccounts();
        if ($accounts === []) {
            throw new RuntimeException('Nenhuma conta de e-mail ativa disponível.');
        }

        return $accounts[0];
    }

    public function listFolders(int $accountId): array
    {
        $folders = $this->folders->listByAccount($accountId);
        $counters = $this->threads->folderCounters($accountId);

        foreach ($folders as &$folder) {
            $id = isset($folder['id']) ? (int)$folder['id'] : null;
            if ($id === null) {
                continue;
            }
            $folderCounters = $counters[$id] ?? ['total' => 0, 'unread' => (int)($folder['unread_count'] ?? 0)];
            $folder['unread_count'] = (int)($folderCounters['unread'] ?? 0);
            $folder['total_count'] = (int)($folderCounters['total'] ?? 0);
        }
        unset($folder);

        return array_map(fn(array $folder): array => $this->formatFolderRow($folder), $folders);
    }

    public function listThreads(int $accountId, array $filters = [], int $limit = 30): array
    {
        $folderId = isset($filters['folder_id']) ? (int)$filters['folder_id'] : null;
        if ($folderId !== null && $folderId <= 0) {
            $folderId = null;
        }

        $effectiveFilters = array_merge($filters, ['folder_id' => $folderId]);
        $threads = $this->threads->recentByAccount($accountId, $effectiveFilters, $limit);

        $normalized = array_map(fn(array $row): array => $this->formatThread($row), $threads);

        if ($folderId === null) {
            $normalized = array_values(array_filter($normalized, static function (array $thread): bool {
                $type = strtolower((string)($thread['folder']['type'] ?? ''));
                return $type !== 'trash' && $type !== 'deleted' && $type !== 'spam';
            }));
        }

        return $normalized;
    }

    public function searchThreads(int $accountId, array $filters = [], int $limit = 50): array
    {
        $folderId = isset($filters['folder_id']) ? (int)$filters['folder_id'] : null;
        if ($folderId !== null && $this->folderOrNull($folderId) === null) {
            $folderId = null;
        }

        $normalizedFilters = [
            'folder_id' => $folderId,
            'query' => $this->normalizeSearchString($filters['query'] ?? null),
            'participant' => $this->normalizeSearchString($filters['participant'] ?? null),
            'has_attachments' => !empty($filters['has_attachments']),
            'unread_only' => !empty($filters['unread_only']),
            'date_from' => $this->normalizeDateBoundary($filters['date_from'] ?? null, false),
            'date_to' => $this->normalizeDateBoundary($filters['date_to'] ?? null, true),
            'mention_email' => $this->normalizeSearchString($filters['mention_email'] ?? null),
        ];

        $threads = $this->threads->searchAdvanced($accountId, $normalizedFilters, $limit);

        return array_map(fn(array $row): array => $this->formatThread($row), $threads);
    }

    public function threadWithMessages(int $threadId, array $options = []): array
    {
        $thread = $this->threads->findWithFolder($threadId);
        if ($thread === null) {
            throw new RuntimeException('Thread não encontrada.');
        }

        $messages = $this->messages->listByThread($threadId, $options);
        $normalized = [];
        foreach ($messages as $message) {
            $messageId = (int)$message['id'];
            $normalized[] = $this->formatMessage(
                $message,
                $this->participants->listByMessage($messageId),
                $this->attachments->listByMessage($messageId)
            );
        }

        return [
            'thread' => $this->formatThread($thread),
            'messages' => array_reverse($normalized),
        ];
    }

    public function markThreadRead(int $threadId, ?int $messageId = null): array
    {
        $thread = $this->threads->findWithFolder($threadId);
        if ($thread === null) {
            throw new RuntimeException('Thread não encontrada.');
        }

        $updated = $this->messages->markThreadMessagesRead($threadId, $messageId);

        if ($updated > 0) {
            $this->threads->touch($threadId, ['unread_increment' => -$updated]);
            if (!empty($thread['folder_id'])) {
                $this->folders->adjustUnreadCount((int)$thread['folder_id'], -$updated);
            }
        }

        $refreshed = $this->threads->findWithFolder($threadId) ?? $thread;
        $folder = null;
        if (!empty($refreshed['folder_id'])) {
            $folderRecord = $this->folders->find((int)$refreshed['folder_id']);
            if ($folderRecord !== null) {
                $folder = $this->formatFolderRow($folderRecord);
            }
        }

        return [
            'updated' => $updated,
            'thread' => $this->formatThread($refreshed),
            'folder' => $folder,
        ];
    }


    public function messageDetail(int $messageId): array
    {
        $message = $this->messages->find($messageId);
        if ($message === null) {
            throw new RuntimeException('Mensagem não encontrada.');
        }

        $thread = null;
        if (!empty($message['thread_id'])) {
            $thread = $this->threads->findWithFolder((int)$message['thread_id']);
        }

        $participants = $this->participants->listByMessage($messageId);
        $attachments = $this->attachments->listByMessage($messageId);

        return [
            'thread' => $thread ? $this->formatThread($thread) : null,
            'message' => array_merge(
                $this->formatMessage($message, $participants, $attachments),
                [
                    'body_text' => $this->loadBody($message['body_text_path'] ?? null),
                    'body_html' => $this->sanitizeHtml($this->loadBody($message['body_html_path'] ?? null)),
                    'folder' => $this->folderOrNull(isset($message['folder_id']) ? (int)$message['folder_id'] : null),
                ]
            ),
        ];
    }

    public function attachmentForDownload(int $attachmentId): array
    {
        $attachment = $this->attachments->find($attachmentId);
        if ($attachment === null) {
            throw new RuntimeException('Anexo não encontrado.');
        }

        $path = $attachment['storage_path'] ?? null;
        if ($path === null || !is_file($path)) {
            throw new RuntimeException('Arquivo de anexo indisponível.');
        }

        $message = null;
        if (!empty($attachment['message_id'])) {
            $messageRow = $this->messages->find((int)$attachment['message_id']);
            if ($messageRow !== null) {
                $message = $this->formatMessage($messageRow, [], []);
            }
        }

        return [
            'path' => $path,
            'filename' => $attachment['filename'] ?? ('attachment-' . $attachmentId),
            'mime_type' => $attachment['mime_type'] ?? 'application/octet-stream',
            'size_bytes' => (int)($attachment['size_bytes'] ?? filesize($path)),
            'message' => $message,
        ];
    }

    public function threadAction(int $threadId, string $action, array $payload = []): array
    {
        $normalized = strtolower(trim($action));

        return match ($normalized) {
            'archive' => $this->archiveThread($threadId),
            'trash', 'delete' => $this->trashThread($threadId),
            'move' => $this->moveThread($threadId, (int)($payload['folder_id'] ?? 0)),
            'star' => $this->toggleStarThread($threadId, !empty($payload['starred'])),
            'mark_read' => $this->markThreadRead($threadId, isset($payload['message_id']) ? (int)$payload['message_id'] : null),
            default => throw new RuntimeException('Ação rápida inválida.'),
        };
    }

    public function bulkThreadAction(array $threadIds, string $action, array $payload = []): array
    {
        $normalizedIds = array_values(array_unique(array_filter(array_map(static fn($value): int => (int)$value, $threadIds), static fn(int $id): bool => $id > 0)));
        if ($normalizedIds === []) {
            throw new RuntimeException('Selecione pelo menos uma conversa.');
        }

        $threads = [];
        $folders = [];
        foreach ($normalizedIds as $threadId) {
            $result = $this->threadAction($threadId, $action, $payload);
            if (!empty($result['thread']) && is_array($result['thread'])) {
                $threads[(int)$result['thread']['id']] = $result['thread'];
            }

            foreach (['folder', 'source_folder', 'target_folder'] as $key) {
                if (empty($result[$key]) || !is_array($result[$key]) || !isset($result[$key]['id'])) {
                    continue;
                }
                $folders[(int)$result[$key]['id']] = $result[$key];
            }
        }

        return [
            'threads' => array_values($threads),
            'folders' => array_values($folders),
            'count' => count($normalizedIds),
        ];
    }

    public function toggleStarThread(int $threadId, bool $starred): array
    {
        $thread = $this->threads->findWithFolder($threadId);
        if ($thread === null) {
            throw new RuntimeException('Thread não encontrada.');
        }

        $flags = array_map(static fn($flag): string => strtolower((string)$flag), $this->decodeFlags($thread['flags'] ?? null));
        $flags = array_values(array_unique($flags));

        if ($starred && !in_array('flagged', $flags, true)) {
            $flags[] = 'flagged';
        }

        if (!$starred) {
            $flags = array_values(array_filter($flags, static fn(string $flag): bool => $flag !== 'flagged'));
        }

        $this->threads->touch($threadId, ['flags' => $flags === [] ? null : json_encode($flags)]);
        $refreshed = $this->threads->findWithFolder($threadId) ?? $thread;

        return ['thread' => $this->formatThread($refreshed)];
    }

    public function moveThread(int $threadId, int $targetFolderId): array
    {
        if ($targetFolderId <= 0) {
            throw new RuntimeException('Selecione uma pasta válida para mover.');
        }

        $thread = $this->threads->findWithFolder($threadId);
        if ($thread === null) {
            throw new RuntimeException('Thread não encontrada.');
        }

        $target = $this->folders->find($targetFolderId);
        if ($target === null) {
            throw new RuntimeException('Pasta destino não encontrada.');
        }

        if ((int)$target['account_id'] !== (int)$thread['account_id']) {
            throw new RuntimeException('A pasta selecionada não pertence a essa conta.');
        }

        $source = null;
        if (!empty($thread['folder_id'])) {
            $source = $this->folders->find((int)$thread['folder_id']);
        }

        if ((int)($thread['folder_id'] ?? 0) === $targetFolderId) {
            return [
                'thread' => $this->formatThread($thread),
                'source_folder' => $source ? $this->formatFolderRow($source) : null,
                'target_folder' => $this->formatFolderRow($target),
            ];
        }

        $this->threads->touch($threadId, ['folder_id' => $targetFolderId]);

        $unread = (int)($thread['unread_count'] ?? 0);
        if ($unread > 0) {
            if ($source !== null) {
                $this->folders->adjustUnreadCount((int)$source['id'], -$unread);
            }
            $this->folders->adjustUnreadCount($targetFolderId, $unread);
        }

        $refreshed = $this->threads->findWithFolder($threadId) ?? $thread;

        return [
            'thread' => $this->formatThread($refreshed),
            'source_folder' => $source ? $this->formatFolderRow($source) : null,
            'target_folder' => $this->formatFolderRow($target),
        ];
    }

    public function archiveThread(int $threadId): array
    {
        $thread = $this->threads->findWithFolder($threadId);
        if ($thread === null) {
            throw new RuntimeException('Thread não encontrada.');
        }

        $archiveFolder = $this->resolveSpecialFolder((int)$thread['account_id'], ['archive'], ['archive', 'arquivadas', 'all mail']);
        if ($archiveFolder === null) {
            throw new RuntimeException('Nenhuma pasta de arquivo foi configurada.');
        }

        return $this->moveThread($threadId, (int)$archiveFolder['id']);
    }

    public function trashThread(int $threadId): array
    {
        $thread = $this->threads->findWithFolder($threadId);
        if ($thread === null) {
            throw new RuntimeException('Thread não encontrada.');
        }

        $trashFolder = $this->resolveSpecialFolder(
            (int)$thread['account_id'],
            ['trash', 'deleted'],
            [
                'trash',
                'lixeira',
                'deleted items',
                'deleted',
                'bin',
                'excluido',
                'excluidos',
                'excluida',
                'excluidas',
                'itens excluido',
                'itens excluidos',
                'itens excluídos',
            ]
        );

        if ($trashFolder === null) {
            throw new RuntimeException('Nenhuma pasta de lixeira foi configurada.');
        }

        return $this->moveThread($threadId, (int)$trashFolder['id']);
    }

    public function emptyTrash(?int $accountId = null): array
    {
        $account = $this->resolveAccount($accountId);
        $resolvedAccountId = (int)$account['id'];

        $trashFolder = $this->resolveSpecialFolder(
            $resolvedAccountId,
            ['trash', 'deleted'],
            [
                'trash',
                'lixeira',
                'deleted items',
                'deleted',
                'bin',
                'excluido',
                'excluidos',
                'excluida',
                'excluidas',
                'itens excluido',
                'itens excluidos',
                'itens excluídos',
            ]
        );

        if ($trashFolder === null) {
            throw new RuntimeException('Nenhuma pasta de lixeira foi configurada.');
        }

        $trashFolderId = (int)$trashFolder['id'];
        $threads = $this->threads->listByFolder($resolvedAccountId, $trashFolderId);
        if ($threads === []) {
            $folderRow = $this->folders->find($trashFolderId) ?? $trashFolder;
            return [
                'status' => 'ok',
                'account_id' => $resolvedAccountId,
                'deleted_threads' => 0,
                'deleted_messages' => 0,
                'folder' => $this->formatFolderRow($folderRow),
            ];
        }

        $threadIds = [];
        $totalUnread = 0;
        $deletedMessages = 0;

        foreach ($threads as $threadRow) {
            $threadId = (int)($threadRow['id'] ?? 0);
            if ($threadId <= 0) {
                continue;
            }

            $threadIds[] = $threadId;
            $totalUnread += (int)($threadRow['unread_count'] ?? 0);

            $messages = $this->messages->listAllByThread($threadId);
            if ($messages === []) {
                continue;
            }

            $messageIds = [];
            foreach ($messages as $message) {
                $messageId = (int)($message['id'] ?? 0);
                if ($messageId <= 0) {
                    continue;
                }

                $messageIds[] = $messageId;
                $this->purgeMessageAttachments($messageId);
                $this->purgeMessageBodies($message);
                $this->participants->deleteByMessage($messageId);
            }

            if ($messageIds !== []) {
                $this->messages->deleteByIds($messageIds);
                $deletedMessages += count($messageIds);
            }
        }

        if ($threadIds !== []) {
            $this->threads->deleteByIds($threadIds);
        }

        if ($totalUnread > 0) {
            $this->folders->adjustUnreadCount($trashFolderId, -$totalUnread);
        }

        $folderRow = $this->folders->find($trashFolderId) ?? $trashFolder;

        return [
            'status' => 'ok',
            'account_id' => $resolvedAccountId,
            'deleted_threads' => count($threadIds),
            'deleted_messages' => $deletedMessages,
            'folder' => $this->formatFolderRow($folderRow),
        ];
    }

    public function listDrafts(int $accountId, int $limit = 10): array
    {
        $rows = $this->messages->listDrafts($accountId, $limit);
        $normalized = [];

        foreach ($rows as $row) {
            $messageId = (int)$row['id'];
            $normalized[] = $this->formatMessage(
                $row,
                $this->participants->listByMessage($messageId),
                $this->attachments->listByMessage($messageId)
            );
        }

        return $normalized;
    }

    public function saveDraft(array $input, array $files = []): array
    {
        $result = $this->persistComposerMessage($input, $files, true, null);

        return [
            'thread' => $result['thread'],
            'message' => $result['message'],
        ];
    }

    public function sendMessage(array $input, array $files = []): array
    {
        $scheduledFor = $this->normalizeScheduledFor($input['scheduled_for'] ?? null);
        $result = $this->persistComposerMessage($input, $files, false, $scheduledFor);

        if ($scheduledFor === null || $scheduledFor <= time()) {
            $this->deliverComposerMessage($result);
        }

        return [
            'thread' => $result['thread'],
            'message' => $result['message'],
        ];
    }

    public function processScheduledDue(?int $accountId = null, int $limit = 20): array
    {
        $due = $this->messages->listScheduledDue($limit, $accountId);
        $sent = [];

        foreach ($due as $row) {
            try {
                $context = $this->hydrateScheduledContext($row);
                if ($context === null) {
                    continue;
                }
                $this->messages->update((int)$row['id'], ['status' => 'pending_send']);
                $this->deliverComposerMessage($context);
                $sent[] = (int)$row['id'];
            } catch (RuntimeException $exception) {
                // Mantém como scheduled; será tentado novamente ou pode ser cancelado.
                @error_log('Falha ao enviar agendado ' . ($row['id'] ?? '??') . ': ' . $exception->getMessage());
            }
        }

        return $sent;
    }

    private function normalizeScheduledFor(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        // Accept HTML datetime-local format (Y-m-dTH:i)
        $timezone = config('app.timezone', 'America/Sao_Paulo');
        $dt = \DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $raw, new \DateTimeZone($timezone));
        if ($dt === false) {
            return null;
        }

        return $dt->getTimestamp();
    }

    private function hydrateScheduledContext(array $messageRow): ?array
    {
        $accountId = (int)($messageRow['account_id'] ?? 0);
        $messageId = (int)($messageRow['id'] ?? 0);
        if ($accountId <= 0 || $messageId <= 0) {
            return null;
        }

        $account = $this->hydrateAccount($this->resolveAccount($accountId));
        $participants = $this->participants->listByMessage($messageId);
        $recipientMatrix = [
            'to' => [],
            'cc' => [],
            'bcc' => [],
        ];

        foreach ($participants as $participant) {
            $role = strtolower((string)($participant['role'] ?? 'to'));
            if (!isset($recipientMatrix[$role])) {
                continue;
            }
            $recipientMatrix[$role][] = [
                'email' => $participant['email'] ?? null,
                'name' => $participant['name'] ?? null,
            ];
        }

        $bodyHtml = $this->loadBody($messageRow['body_html_path'] ?? null);
        $bodyText = $this->loadBody($messageRow['body_text_path'] ?? null);

        $attachments = $this->attachments->listByMessage($messageId);

        return [
            'thread' => $this->formatThread($this->threads->findWithFolder((int)($messageRow['thread_id'] ?? 0))),
            'message' => $this->formatMessage($messageRow, $participants, $attachments),
            'account' => $account,
            'recipients' => $recipientMatrix,
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'subject' => (string)($messageRow['subject'] ?? ''),
            'message_identifier' => $messageRow['internet_message_id'] ?? $this->generateMessageIdentifier($account['from_email']),
            'message_id' => $messageId,
            'attachments' => array_map([$this, 'formatAttachment'], $attachments),
        ];
    }

    public function folderOrNull(?int $folderId): ?array
    {
        if ($folderId === null) {
            return null;
        }

        $folder = $this->folders->find($folderId);
        if ($folder === null) {
            return null;
        }

        return $this->formatFolderRow($folder);
    }

    private function resolveSpecialFolder(int $accountId, array $types, array $names = []): ?array
    {
        foreach ($types as $type) {
            $folder = $this->folders->findByType($accountId, $type);
            if ($folder !== null) {
                return $folder;
            }
        }

        if ($names === []) {
            return null;
        }

        $candidates = $this->folders->listByAccount($accountId);
        $normalizedNames = array_filter(array_map([$this, 'normalizeFolderLabel'], $names));

        foreach ($candidates as $folder) {
            $label = $this->normalizeFolderLabel($folder['display_name'] ?? $folder['remote_name'] ?? '');
            if ($label === '') {
                continue;
            }
            foreach ($normalizedNames as $needle) {
                if ($needle === '') {
                    continue;
                }
                if ($label === $needle || str_contains($label, $needle)) {
                    return $folder;
                }
            }
        }

        return null;
    }

    private function normalizeFolderLabel(?string $value): string
    {
        $label = strtolower(trim((string)($value ?? '')));
        if ($label === '') {
            return '';
        }

        if (class_exists(\Normalizer::class)) {
            $normalized = @\Normalizer::normalize($label, \Normalizer::FORM_KD);
            if (is_string($normalized) && $normalized !== '') {
                $label = strtolower($normalized);
            }
        }

        $transliterated = @iconv('UTF-8', 'ASCII//TRANSLIT', $label);
        if (is_string($transliterated) && $transliterated !== '') {
            $label = strtolower($transliterated);
        }

        $label = preg_replace('/[^a-z0-9 ]+/i', ' ', $label) ?? $label;
        return trim(preg_replace('/\s+/', ' ', $label) ?? $label);
    }

    private function persistComposerMessage(array $input, array $files, bool $asDraft, ?int $scheduledFor): array
    {
        $account = $this->resolveAccount(isset($input['account_id']) ? (int)$input['account_id'] : null);
        $hydratedAccount = $this->hydrateAccount($account);

        if (empty($hydratedAccount['from_email'])) {
            throw new RuntimeException('A conta selecionada não possui remetente configurado.');
        }

        $subject = trim((string)($input['subject'] ?? ''));
        $subject = $subject === '' ? '(sem assunto)' : $subject;

        $bodyHtml = array_key_exists('body_html', $input) ? (string)$input['body_html'] : null;
        $bodyText = array_key_exists('body_text', $input) ? (string)$input['body_text'] : null;

        if ($bodyHtml === null && $bodyText === null) {
            if ($asDraft) {
                $bodyText = '';
            } else {
                throw new RuntimeException('Inclua o corpo da mensagem.');
            }
        }

        if ($bodyText === null && $bodyHtml !== null) {
            $bodyText = strip_tags($bodyHtml);
        }

        $recipients = $this->parseAddressList($input['to'] ?? null);
        $ccRecipients = $this->parseAddressList($input['cc'] ?? null);
        $bccRecipients = $this->parseAddressList($input['bcc'] ?? null);
        $inheritAttachmentIds = $this->normalizeAttachmentIdList($input['inherit_attachment_ids'] ?? null);

        $hasRecipients = $recipients !== [] || $ccRecipients !== [] || $bccRecipients !== [];
        if (!$asDraft && !$hasRecipients) {
            throw new RuntimeException('Informe pelo menos um destinatário.');
        }

        $draftId = isset($input['draft_id']) ? (int)$input['draft_id'] : null;
        if ($draftId !== null && $draftId <= 0) {
            $draftId = null;
        }

        $existing = null;
        if ($draftId !== null) {
            $existing = $this->messages->find($draftId);
            if ($existing === null) {
                throw new RuntimeException('Rascunho não encontrado.');
            }
            if ((int)$existing['account_id'] !== (int)$account['id']) {
                throw new RuntimeException('O rascunho não pertence a essa conta.');
            }
            $status = strtolower((string)($existing['status'] ?? 'draft'));
            if (
                !$asDraft
                && !in_array($status, ['draft', 'failed', 'pending_send'], true)
            ) {
                throw new RuntimeException('Essa mensagem já foi enviada. Crie um novo rascunho para reenviar.');
            }
        }

        $targetFolder = $this->resolveSpecialFolder(
            (int)$account['id'],
            $asDraft ? ['drafts'] : ['sent'],
            $asDraft ? ['drafts', 'rascunhos'] : ['sent', 'enviados']
        );
        $targetFolderId = $targetFolder !== null ? (int)$targetFolder['id'] : null;

        $threadIdInput = isset($input['thread_id']) ? (int)$input['thread_id'] : null;
        if ($threadIdInput !== null && $threadIdInput <= 0) {
            $threadIdInput = null;
        }

        if ($existing !== null && !empty($existing['thread_id'])) {
            $threadIdInput = (int)$existing['thread_id'];
        }

        $threadRow = $this->ensureThreadForCompose(
            (int)$account['id'],
            $threadIdInput,
            $subject,
            $targetFolderId,
            $threadIdInput !== null,
            $asDraft
        );
        $threadId = (int)$threadRow['id'];

        $snippet = $this->buildSnippetFromComposer($bodyHtml, $bodyText);
        $messageIdentifier = ($existing['internet_message_id'] ?? null) ?: $this->generateMessageIdentifier($hydratedAccount['from_email']);

        $existingPaths = [
            'text' => $existing['body_text_path'] ?? null,
            'html' => $existing['body_html_path'] ?? null,
        ];
        $bodyPaths = $this->persistOutgoingBodies((int)$account['id'], $bodyText, $bodyHtml, $existingPaths);

        $uploadedFiles = [];
        foreach ($files as $file) {
            if ($file instanceof UploadedFile) {
                $uploadedFiles[] = $file;
                continue;
            }
            if (is_array($file)) {
                foreach ($file as $inner) {
                    if ($inner instanceof UploadedFile) {
                        $uploadedFiles[] = $inner;
                    }
                }
            }
        }

        $uploadedAttachments = $this->storeUploadedAttachments((int)$account['id'], $uploadedFiles);
        $inheritedAttachments = $inheritAttachmentIds !== []
            ? $this->duplicateExistingAttachments((int)$account['id'], $inheritAttachmentIds)
            : [];
        $storedAttachments = array_merge($inheritedAttachments, $uploadedAttachments);
        $shouldReplaceAttachments = $storedAttachments !== [] || !empty($input['clear_attachments']);

        $status = $asDraft ? 'draft' : ($scheduledFor !== null && $scheduledFor > time() ? 'scheduled' : 'pending_send');

        $payload = [
            'thread_id' => $threadId,
            'account_id' => (int)$account['id'],
            'folder_id' => $targetFolderId,
            'direction' => 'outbound',
            'status' => $status,
            'subject' => $subject,
            'sender_name' => $hydratedAccount['from_name'],
            'sender_email' => $hydratedAccount['from_email'],
            'to_recipients' => $this->encodeJson($recipients),
            'cc_recipients' => $this->encodeJson($ccRecipients),
            'bcc_recipients' => $this->encodeJson($bccRecipients),
            'internet_message_id' => $messageIdentifier,
            'in_reply_to' => $input['in_reply_to'] ?? null,
            'references_header' => $input['references'] ?? null,
            'sent_at' => null,
            'received_at' => null,
            'body_text_path' => $bodyPaths['text'],
            'body_html_path' => $bodyPaths['html'],
            'headers' => null,
            'metadata' => $this->encodeJson([
                'composer' => true,
                'scheduled_for' => $scheduledFor,
            ]),
            'hash' => sha1((int)$account['id'] . ':' . microtime(true)),
            'snippet' => $snippet,
            'scheduled_for' => $scheduledFor,
        ];

        if ($existing === null) {
            $messageId = $this->messages->insert($payload);
        } else {
            $messageId = (int)$existing['id'];
            $this->messages->update($messageId, $payload);
        }

        if ($shouldReplaceAttachments) {
            $this->purgeMessageAttachments($messageId);
        }

        if ($storedAttachments !== []) {
            $this->attachments->insertMany($messageId, $storedAttachments);
        }

        $participants = $this->composeParticipants($hydratedAccount, $recipients, $ccRecipients, $bccRecipients);
        $this->participants->replaceForMessage($messageId, $participants);

        $this->threads->touch($threadId, [
            'snippet' => $snippet,
            'last_message_at' => time(),
            'folder_id' => $threadIdInput !== null ? null : $targetFolderId,
        ]);

        $threadSnapshot = $this->threads->findWithFolder($threadId) ?? $threadRow;
        $messageSnapshot = $this->messages->find($messageId);
        $attachments = $this->attachments->listByMessage($messageId);
        $sizeBytes = $this->estimateMessageSize($bodyHtml, $bodyText, $attachments);
        $this->messages->update($messageId, ['size_bytes' => $sizeBytes]);
        $formattedMessage = $this->formatMessage(
            $messageSnapshot,
            $this->participants->listByMessage($messageId),
            $attachments
        );

        return [
            'thread' => $this->formatThread($threadSnapshot),
            'message' => $formattedMessage,
            'account' => $hydratedAccount,
            'recipients' => [
                'to' => $recipients,
                'cc' => $ccRecipients,
                'bcc' => $bccRecipients,
            ],
            'body_html' => $bodyHtml,
            'body_text' => $bodyText,
            'subject' => $subject,
            'message_identifier' => $messageIdentifier,
            'message_id' => $messageId,
            'attachments' => $attachments,
            'draft' => $asDraft,
        ];
    }

    private function deliverComposerMessage(array $context): void
    {
        $recipientMatrix = [
            'to' => $context['recipients']['to'] ?? [],
            'cc' => $context['recipients']['cc'] ?? [],
            'bcc' => $context['recipients']['bcc'] ?? [],
        ];

        $allRecipients = $this->flattenRecipientLists($recipientMatrix);
        if ($allRecipients === []) {
            return;
        }

        $contactRepo = new MarketingContactRepository();
        $clientRepo = new ClientRepository();
        $mailer = new SmtpMailer([
            'host' => $context['account']['smtp_host'],
            'port' => $context['account']['smtp_port'],
            'encryption' => $context['account']['encryption'],
            'auth_mode' => $context['account']['auth_mode'],
            'username' => $context['account']['username'],
            'password' => $context['account']['password'],
        ]);

        $attachments = array_filter($context['attachments'], static function (array $attachment): bool {
            return !empty($attachment['storage_path']) && is_file($attachment['storage_path']);
        });

        try {
            foreach ($allRecipients as $recipient) {
                $contact = $contactRepo->findByEmail($recipient['email']);
                $client = null;
                if (!empty($contact['crm_client_id'])) {
                    $client = $clientRepo->find((int)$contact['crm_client_id']);
                }
                if ($client === null) {
                    $client = $clientRepo->findByEmail($recipient['email']);
                }

                $personalized = $this->personalizeComposerContent(
                    $context['subject'],
                    $context['body_html'],
                    $context['body_text'],
                    $recipient,
                    $contact,
                    $client
                );

                $raw = MimeMessageBuilder::build([
                    'from_email' => $context['account']['from_email'],
                    'from_name' => $context['account']['from_name'],
                    'to_list' => [['email' => $recipient['email'], 'name' => $recipient['name'] ?? null]],
                    'cc_list' => [],
                    'bcc_list' => [],
                    'subject' => $personalized['subject'],
                    'body_html' => $personalized['body_html'],
                    'body_text' => $personalized['body_text'],
                    'reply_to' => $context['account']['reply_to'],
                    'headers' => ['Message-ID' => $context['message_identifier']],
                    'attachments' => array_map(static function (array $attachment): array {
                        return [
                            'filename' => $attachment['filename'],
                            'mime_type' => $attachment['mime_type'] ?? 'application/octet-stream',
                            'path' => $attachment['storage_path'],
                        ];
                    }, $attachments),
                ]);

                $mailer->send([
                    'from' => $context['account']['from_email'],
                    'recipients' => [$recipient['email']],
                    'data' => $raw,
                ]);
            }

            $this->messages->update($context['message_id'], [
                'status' => 'sent',
                'sent_at' => time(),
            ]);
        } catch (\Throwable $exception) {
            $this->messages->update($context['message_id'], [
                'status' => 'failed',
                'metadata' => $this->encodeJson([
                    'composer' => 'failed',
                    'message' => $exception->getMessage(),
                ]),
            ]);

            throw $exception;
        }
    }

    private function personalizeComposerContent(
        ?string $subject,
        ?string $bodyHtml,
        ?string $bodyText,
        array $recipient,
        ?array $contact,
        ?array $client
    ): array {
        $tokens = $this->buildPersonalizationTokens($recipient, $contact, $client);

        $replace = static function (?string $content) use ($tokens): ?string {
            if ($content === null) {
                return null;
            }
            return str_replace(array_keys($tokens), array_values($tokens), $content);
        };

        return [
            'subject' => $replace($subject) ?? '',
            'body_html' => $replace($bodyHtml) ?? '',
            'body_text' => $replace($bodyText) ?? '',
        ];
    }

    private function buildPersonalizationTokens(array $recipient, ?array $contact, ?array $client): array
    {
        $contactFirst = trim((string)($contact['first_name'] ?? ''));
        $contactLast = trim((string)($contact['last_name'] ?? ''));
        $contactName = trim($contactFirst . ' ' . $contactLast);

        if ($contactName === '' && !empty($recipient['name'])) {
            $contactName = trim((string)$recipient['name']);
        }

        if ($contactName === '') {
            $contactName = ucfirst(strtok((string)$recipient['email'], '@'));
        }

        $company = (string)($client['name'] ?? '');

        $document = (string)($client['document'] ?? '');
        $documentDigits = preg_replace('/[^0-9]/', '', $document) ?? '';
        $isCnpj = strlen($documentDigits) === 14;
        $isCpf = strlen($documentDigits) === 11;
        $formattedDoc = $this->formatDocument($documentDigits);

        $titularName = trim((string)($client['titular_name'] ?? ''));
        if ($titularName === '' && $company !== '') {
            $titularName = $company;
        }
        if ($titularName === '') {
            $titularName = $contactName;
        }

        $titularDoc = $this->formatDocument(preg_replace('/[^0-9]/', '', (string)($client['titular_document'] ?? '')) ?? '');
        $birthdate = $this->formatBirthdate(isset($client['titular_birthdate']) ? (int)$client['titular_birthdate'] : null);

        return [
            '{{nome}}' => $contactName,
            '{{empresa}}' => $company,
            '{{razao_social}}' => $company,
            '{{email}}' => (string)($recipient['email'] ?? ''),
            '{{documento}}' => $formattedDoc,
            '{{cnpj}}' => $isCnpj ? $formattedDoc : '',
            '{{cpf}}' => $isCpf ? $formattedDoc : '',
            '{{titular_nome}}' => $titularName !== '' ? $titularName : $contactName,
            '{{titular_documento}}' => $titularDoc,
            '{{data_nascimento}}' => $birthdate,
        ];
    }

    private function formatDocument(string $digits): string
    {
        $len = strlen($digits);
        if ($len === 14) {
            return sprintf('%s.%s.%s/%s-%s',
                substr($digits, 0, 2),
                substr($digits, 2, 3),
                substr($digits, 5, 3),
                substr($digits, 8, 4),
                substr($digits, 12, 2)
            );
        }
        if ($len === 11) {
            return sprintf('%s.%s.%s-%s',
                substr($digits, 0, 3),
                substr($digits, 3, 3),
                substr($digits, 6, 3),
                substr($digits, 9, 2)
            );
        }
        return $digits;
    }

    private function formatBirthdate(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '';
        }
        return date('d/m/Y', $timestamp);
    }

    private function hydrateAccount(array $account): array
    {
        $credentials = $this->decryptCredentials($this->decodeJson($account['credentials'] ?? null));

        return [
            'id' => (int)$account['id'],
            'from_name' => $account['from_name'] ?? null,
            'from_email' => $account['from_email'] ?? null,
            'reply_to' => $account['reply_to'] ?? null,
            'smtp_host' => $account['smtp_host'] ?? 'localhost',
            'smtp_port' => (int)($account['smtp_port'] ?? 587),
            'encryption' => $account['encryption'] ?? 'tls',
            'auth_mode' => $account['auth_mode'] ?? 'login',
            'username' => $credentials['username'] ?? null,
            'password' => $credentials['password'] ?? null,
        ];
    }

    private function decryptCredentials(array $credentials): array
    {
        return [
            'username' => $credentials['username'] ?? null,
            'password' => $this->decryptSecret($credentials['password'] ?? null),
            'oauth_token' => $this->decryptSecret($credentials['oauth_token'] ?? null),
            'api_key' => $this->decryptSecret($credentials['api_key'] ?? null),
        ];
    }

    private function decryptSecret(mixed $value): ?string
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
        } catch (RuntimeException) {
            return null;
        }
    }

    private function parseAddressList(?string $raw): array
    {
        if ($raw === null) {
            return [];
        }

        $parts = preg_split('/[,;]+/', (string)$raw) ?: [];
        $recipients = [];

        foreach ($parts as $entry) {
            $trimmed = trim($entry);
            if ($trimmed === '') {
                continue;
            }

            $name = null;
            $email = $trimmed;

            if (str_contains($trimmed, '<')) {
                $matches = [];
                if (preg_match('/(.+)<([^>]+)>/', $trimmed, $matches) === 1) {
                    $name = trim($matches[1], " \"\t");
                    $email = trim($matches[2]);
                }
            }

            if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $normalizedEmail = strtolower($email);
            $recipients[$normalizedEmail] = [
                'email' => $normalizedEmail,
                'name' => $name ?: null,
            ];
        }

        return array_values($recipients);
    }

    private function normalizeAttachmentIdList(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $value = preg_split('/[,;]+/', $value) ?: [];
        }

        if (!is_array($value)) {
            return [];
        }

        $ids = [];
        foreach ($value as $entry) {
            $id = (int)$entry;
            if ($id > 0) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function composeParticipants(array $account, array $toRecipients, array $ccRecipients, array $bccRecipients): array
    {
        $participants = [[
            'role' => 'from',
            'name' => $account['from_name'],
            'email' => $account['from_email'],
        ]];

        foreach ($toRecipients as $recipient) {
            $participants[] = [
                'role' => 'to',
                'name' => $recipient['name'] ?? null,
                'email' => $recipient['email'],
            ];
        }

        foreach ($ccRecipients as $recipient) {
            $participants[] = [
                'role' => 'cc',
                'name' => $recipient['name'] ?? null,
                'email' => $recipient['email'],
            ];
        }

        foreach ($bccRecipients as $recipient) {
            $participants[] = [
                'role' => 'bcc',
                'name' => $recipient['name'] ?? null,
                'email' => $recipient['email'],
            ];
        }

        return $participants;
    }

    private function flattenRecipientLists(array $matrix): array
    {
        $unique = [];
        foreach (['to', 'cc', 'bcc'] as $key) {
            if (empty($matrix[$key]) || !is_array($matrix[$key])) {
                continue;
            }
            foreach ($matrix[$key] as $recipient) {
                if (!is_array($recipient) || empty($recipient['email'])) {
                    continue;
                }
                $email = strtolower(trim((string)$recipient['email']));
                if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                    continue;
                }
                if (isset($unique[$email])) {
                    continue;
                }
                $unique[$email] = [
                    'email' => $email,
                    'name' => $recipient['name'] ?? null,
                ];
            }
        }

        return array_values($unique);
    }

    private function buildSnippetFromComposer(?string $bodyHtml, ?string $bodyText): string
    {
        $source = $bodyText;
        if ($source === null && $bodyHtml !== null) {
            $source = strip_tags($bodyHtml);
        }

        if ($source === null) {
            return '';
        }

        $text = preg_replace('/\s+/', ' ', $source ?? '') ?? '';
        $snippet = function_exists('mb_substr') ? mb_substr($text, 0, 180) : substr($text, 0, 180);

        return trim((string)$snippet);
    }

    private function persistOutgoingBodies(int $accountId, ?string $bodyText, ?string $bodyHtml, array $existingPaths = []): array
    {
        $paths = [
            'text' => $existingPaths['text'] ?? null,
            'html' => $existingPaths['html'] ?? null,
        ];

        $directory = storage_path('email' . DIRECTORY_SEPARATOR . 'outgoing' . DIRECTORY_SEPARATOR . $accountId);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        if ($bodyText !== null) {
            if ($paths['text'] && is_file($paths['text'])) {
                @unlink($paths['text']);
            }
            $paths['text'] = $this->writeBodyFile($directory, 'txt', $bodyText);
        }

        if ($bodyHtml !== null) {
            if ($paths['html'] && is_file($paths['html'])) {
                @unlink($paths['html']);
            }
            $paths['html'] = $this->writeBodyFile($directory, 'html', $bodyHtml);
        }

        return $paths;
    }

    private function writeBodyFile(string $directory, string $extension, string $contents): string
    {
        $filename = sprintf('%s_%s.%s', time(), bin2hex(random_bytes(4)), $extension);
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        file_put_contents($path, $contents);

        return $path;
    }

    /**
     * @param UploadedFile[] $files
     */
    private function storeUploadedAttachments(int $accountId, array $files): array
    {
        if ($files === []) {
            return [];
        }

        $directory = storage_path('email' . DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR . $accountId);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $stored = [];
        foreach ($files as $file) {
            if (!$file instanceof UploadedFile || !$file->isValid()) {
                continue;
            }

            $originalName = $file->getClientOriginalName() ?: $file->getFilename();
            $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'bin';
            $basename = pathinfo($originalName, PATHINFO_FILENAME);
            $safeBase = preg_replace('/[^A-Za-z0-9_-]/', '_', $basename) ?: 'attachment';
            $filename = sprintf('%s_%d_%s.%s', $safeBase, time(), bin2hex(random_bytes(3)), $extension);

            $file->move($directory, $filename);
            $path = $directory . DIRECTORY_SEPARATOR . $filename;

            $stored[] = [
                'filename' => $originalName,
                'mime_type' => $file->getClientMimeType(),
                'size_bytes' => filesize($path),
                'storage_path' => $path,
                'checksum' => sha1_file($path),
            ];
        }

        return $stored;
    }

    private function duplicateExistingAttachments(int $accountId, array $attachmentIds): array
    {
        if ($attachmentIds === []) {
            return [];
        }

        $directory = storage_path('email' . DIRECTORY_SEPARATOR . 'attachments' . DIRECTORY_SEPARATOR . $accountId);
        if (!is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $copies = [];
        foreach ($attachmentIds as $attachmentId) {
            $record = $this->attachments->find($attachmentId);
            if ($record === null) {
                continue;
            }

            $sourcePath = $record['storage_path'] ?? null;
            if ($sourcePath === null || !is_file($sourcePath)) {
                continue;
            }

            $originalName = $record['filename'] ?? basename($sourcePath);
            $extension = pathinfo($originalName, PATHINFO_EXTENSION) ?: 'bin';
            $baseName = pathinfo($originalName, PATHINFO_FILENAME);
            $safeBase = preg_replace('/[^A-Za-z0-9_-]/', '_', $baseName) ?: 'attachment';
            $filename = sprintf('%s_fwd_%s.%s', $safeBase, bin2hex(random_bytes(3)), $extension);
            $targetPath = $directory . DIRECTORY_SEPARATOR . $filename;

            if (!@copy($sourcePath, $targetPath)) {
                continue;
            }

            $copies[] = [
                'filename' => $originalName,
                'mime_type' => $record['mime_type'] ?? 'application/octet-stream',
                'size_bytes' => is_file($targetPath) ? filesize($targetPath) : (int)($record['size_bytes'] ?? 0),
                'storage_path' => $targetPath,
                'checksum' => sha1_file($targetPath),
            ];
        }

        return $copies;
    }

    private function purgeMessageAttachments(int $messageId): void
    {
        $current = $this->attachments->listByMessage($messageId);
        foreach ($current as $attachment) {
            $path = $attachment['storage_path'] ?? null;
            if ($path && is_file($path)) {
                @unlink($path);
            }
        }

        $this->attachments->deleteByMessage($messageId);
    }

    private function purgeMessageBodies(array $message): void
    {
        foreach (['body_text_path', 'body_html_path'] as $field) {
            $path = isset($message[$field]) ? (string)$message[$field] : '';
            if ($path === '' || !is_file($path)) {
                continue;
            }
            @unlink($path);
        }
    }

    private function ensureThreadForCompose(int $accountId, ?int $threadId, string $subject, ?int $folderId, bool $preserveFolder, bool $markAsDraft): array
    {
        if ($threadId !== null) {
            $thread = $this->threads->findWithFolder($threadId);
            if ($thread === null || (int)$thread['account_id'] !== $accountId) {
                throw new RuntimeException('Thread alvo inválida.');
            }

            if (!$preserveFolder && $folderId !== null && (int)($thread['folder_id'] ?? 0) !== $folderId) {
                $this->threads->touch($threadId, ['folder_id' => $folderId]);
                $thread = $this->threads->findWithFolder($threadId) ?? $thread;
            }

            return $thread;
        }

        $threadId = $this->threads->create([
            'account_id' => $accountId,
            'folder_id' => $folderId,
            'subject' => $subject,
            'snippet' => null,
            'primary_contact_id' => null,
            'primary_client_id' => null,
            'last_message_at' => time(),
            'unread_count' => 0,
            'flags' => $markAsDraft ? json_encode(['draft']) : null,
        ]);

        return $this->threads->findWithFolder($threadId) ?? ['id' => $threadId, 'account_id' => $accountId, 'folder_id' => $folderId];
    }

    private function generateMessageIdentifier(string $fromEmail): string
    {
        $domain = str_contains($fromEmail, '@') ? substr(strrchr($fromEmail, '@'), 1) : 'localhost';

        return sprintf('<%s@%s>', bin2hex(random_bytes(8)), $domain ?: 'localhost');
    }

    private function estimateMessageSize(?string $bodyHtml, ?string $bodyText, array $attachments): int
    {
        $size = 0;
        if ($bodyHtml !== null) {
            $size += strlen($bodyHtml);
        }
        if ($bodyText !== null) {
            $size += strlen($bodyText);
        }

        foreach ($attachments as $attachment) {
            $size += (int)($attachment['size_bytes'] ?? 0);
        }

        return $size;
    }

    private function encodeJson(mixed $value): string
    {
        return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function decodeJson(mixed $value): array
    {
        if (!is_string($value) || trim($value) === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function formatFolderRow(array $folder): array
    {
        return [
            'id' => (int)$folder['id'],
            'display_name' => $folder['display_name'] ?? $folder['remote_name'] ?? 'Desconhecida',
            'remote_name' => $folder['remote_name'] ?? null,
            'type' => $folder['type'] ?? 'custom',
            'unread_count' => (int)($folder['unread_count'] ?? 0),
            'total_count' => (int)($folder['total_count'] ?? 0),
        ];
    }

    private function normalizeSearchString(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);
        return $value === '' ? null : $value;
    }

    private function normalizeDateBoundary(mixed $value, bool $endOfDay): ?int
    {
        if ($value === null) {
            return null;
        }

        $raw = trim((string)$value);
        if ($raw === '') {
            return null;
        }

        try {
            $date = new DateTimeImmutable($raw);
        } catch (\Exception $exception) {
            return null;
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) === 1) {
            $date = $date->setTime($endOfDay ? 23 : 0, $endOfDay ? 59 : 0, $endOfDay ? 59 : 0);
        }

        return $date->getTimestamp();
    }


    private function formatThread(array $thread): array
    {
        $folder = [
            'id' => isset($thread['folder_id']) ? (int)$thread['folder_id'] : null,
            'display_name' => $thread['folder_name'] ?? $thread['remote_name'] ?? 'Sem pasta',
            'type' => $thread['folder_type'] ?? 'custom',
            'unread_count' => isset($thread['folder_unread_count']) ? (int)$thread['folder_unread_count'] : null,
        ];

        return [
            'id' => (int)$thread['id'],
            'account_id' => (int)$thread['account_id'],
            'subject' => $thread['subject'] ?? '(sem assunto)',
            'snippet' => $thread['snippet'] ?? null,
            'unread_count' => (int)($thread['unread_count'] ?? 0),
            'last_message_at' => isset($thread['last_message_at']) ? (int)$thread['last_message_at'] : null,
            'updated_at' => isset($thread['updated_at']) ? (int)$thread['updated_at'] : null,
            'folder' => $folder,
            'flags' => $this->decodeFlags($thread['flags'] ?? null),
            'primary_contact_id' => $thread['primary_contact_id'] !== null ? (int)$thread['primary_contact_id'] : null,
            'primary_client_id' => $thread['primary_client_id'] !== null ? (int)$thread['primary_client_id'] : null,
        ];
    }

    private function formatMessage(array $message, array $participants = [], array $attachments = []): array
    {
        return [
            'id' => (int)$message['id'],
            'thread_id' => (int)$message['thread_id'],
            'account_id' => (int)$message['account_id'],
            'folder_id' => isset($message['folder_id']) ? (int)$message['folder_id'] : null,
            'direction' => $message['direction'] ?? 'inbound',
            'status' => $message['status'] ?? 'synced',
            'subject' => $message['subject'] ?? '(sem assunto)',
            'snippet' => $message['snippet'] ?? null,
            'body_preview' => $message['body_preview'] ?? null,
            'sent_at' => isset($message['sent_at']) ? (int)$message['sent_at'] : null,
            'received_at' => isset($message['received_at']) ? (int)$message['received_at'] : null,
            'internet_message_id' => $message['internet_message_id'] ?? null,
            'external_uid' => $message['external_uid'] ?? null,
            'participants' => array_map([$this, 'formatParticipant'], $participants),
            'attachments' => array_map([$this, 'formatAttachment'], $attachments),
            'has_attachments' => $attachments !== [],
        ];
    }

    private function formatParticipant(array $participant): array
    {
        return [
            'role' => $participant['role'] ?? 'to',
            'name' => $participant['name'] ?? null,
            'email' => $participant['email'] ?? null,
            'contact_id' => $participant['contact_id'] !== null ? (int)$participant['contact_id'] : null,
            'client_id' => $participant['client_id'] !== null ? (int)$participant['client_id'] : null,
        ];
    }

    private function formatAttachment(array $attachment): array
    {
        return [
            'id' => (int)$attachment['id'],
            'filename' => $attachment['filename'],
            'mime_type' => $attachment['mime_type'] ?? null,
            'size_bytes' => (int)($attachment['size_bytes'] ?? 0),
            'storage_path' => $attachment['storage_path'] ?? null,
            'checksum' => $attachment['checksum'] ?? null,
        ];
    }

    private function decodeFlags(?string $flags): array
    {
        if ($flags === null || trim($flags) === '') {
            return [];
        }

        $decoded = json_decode($flags, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        return array_filter(array_map('trim', explode(',', $flags)), static fn(string $flag): bool => $flag !== '');
    }

    private function loadBody(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return null;
        }

        if (!is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        return $contents === false ? null : $contents;
    }

    private function sanitizeHtml(?string $html): ?string
    {
        if ($html === null) {
            return null;
        }

        $clean = preg_replace('/<script\b[^<]*(?:(?!<\/script>)<[^<]*)*<\/script>/is', '', $html) ?? $html;
        return $clean;
    }
}
