<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthenticatedUser;
use App\Repositories\ChatExternalLeadRepository;
use App\Repositories\ChatMessageRepository;
use App\Repositories\ChatThreadRepository;
use App\Repositories\SettingRepository;
use App\Repositories\UserRepository;

final class ChatService
{
    private const MAX_MESSAGE_LENGTH = 4000;


    /**
     * @param array<int, array<string, mixed>> $users
     * @return int[]
     */
    private function filterExternalRecipients(array $users): array
    {
        $ids = [];
        foreach ($users as $user) {
            $id = (int)($user['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            $allowInternal = ((int)($user['allow_internal_chat'] ?? 1)) === 1;
            $allowExternal = ((int)($user['allow_external_chat'] ?? 1)) === 1;
            if ($allowInternal && $allowExternal) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function canUseInternalChat(AuthenticatedUser $user): bool
    {
        return $user->isAdmin() || $user->allowInternalChat;
    }

    private function canHandleExternalLeads(AuthenticatedUser $user): bool
    {
        return $user->isAdmin() || $user->allowExternalChat;
    }

    private function userAllowsInternalChat(array $user): bool
    {
        return ((int)($user['allow_internal_chat'] ?? 1)) === 1;
    }

    public function __construct(
        private readonly ChatThreadRepository $threads = new ChatThreadRepository(),
        private readonly ChatMessageRepository $messages = new ChatMessageRepository(),
        private readonly UserRepository $users = new UserRepository(),
        private readonly ChatExternalLeadRepository $externalLeads = new ChatExternalLeadRepository(),
        private readonly SettingRepository $settings = new SettingRepository()
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function threadsForSidebar(AuthenticatedUser $user): array
    {
        if (!$this->canUseInternalChat($user)) {
            return [];
        }

        $threads = $user->isAdmin()
            ? $this->threads->listOverviewForAdmin(60)
            : $this->threads->listOverviewForUser($user->id, 60);

        $externalThreadIds = [];
        foreach ($threads as &$thread) {
            $participants = $this->threads->participantsWithProfiles((int)$thread['id']);
            $thread['participants'] = $participants;
            $thread['display_name'] = $this->resolveThreadName($thread, $participants, $user->id);
            $thread['last_message_preview'] = $this->previewBody($thread['last_message_body'] ?? '');
            if (($thread['type'] ?? '') === 'external') {
                $externalThreadIds[] = (int)($thread['id'] ?? 0);
            }
        }
        unset($thread);

        if ($externalThreadIds !== []) {
            $leadMap = $this->externalLeads->mapByThreadIds($externalThreadIds);
            foreach ($leadMap as $mapThreadId => $leadDetails) {
                $leadMap[$mapThreadId] = $this->enrichLead($leadDetails);
            }
            foreach ($threads as &$thread) {
                if (($thread['type'] ?? '') === 'external') {
                    $thread['external_lead'] = $leadMap[(int)($thread['id'] ?? 0)] ?? null;
                }
            }
            unset($thread);
        }

        if (!$this->canHandleExternalLeads($user)) {
            $threads = array_values(array_filter($threads, static function (array $thread): bool {
                return ($thread['type'] ?? 'direct') !== 'external';
            }));
        }

        return $threads;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function loadThread(int $threadId, AuthenticatedUser $user): ?array
    {
        if (!$this->canUseInternalChat($user)) {
            return null;
        }

        $participant = $this->threads->participantRecord($threadId, $user->id);
        if ($participant === null && !$user->isAdmin()) {
            return null;
        }

        $thread = $this->threads->find($threadId);
        if ($thread === null) {
            return null;
        }

        if (($thread['status'] ?? 'open') === 'closed' && !$user->isAdmin()) {
            return null;
        }

        if (($thread['type'] ?? '') === 'external' && !$this->canHandleExternalLeads($user)) {
            return null;
        }

        $participants = $this->threads->participantsWithProfiles($threadId);
        $thread['participants'] = $participants;
        $thread['display_name'] = $this->resolveThreadName($thread, $participants, $user->id);
        if (($thread['type'] ?? '') === 'external') {
            $lead = $this->externalLeads->findByThread($threadId);
            $thread['external_lead'] = $this->enrichLead($lead);
        }

        return $thread;
    }

    /**
     * @return array<int, array<string, mixed>>|null
     */
    public function messagesForThread(int $threadId, AuthenticatedUser $user, int $limit = 50, ?int $beforeId = null, ?int $afterId = null): ?array
    {
        if (!$this->canUseInternalChat($user)) {
            return null;
        }

        $thread = $this->threads->find($threadId);
        if ($thread === null) {
            return null;
        }

        if (($thread['status'] ?? 'open') === 'closed' && !$user->isAdmin()) {
            return null;
        }

        if ($this->threads->participantRecord($threadId, $user->id) === null && !$user->isAdmin()) {
            return null;
        }

        if (($thread['type'] ?? '') === 'external' && !$this->canHandleExternalLeads($user)) {
            return null;
        }

        return $this->messages->listForThread($threadId, $limit, $beforeId, $afterId);
    }

    /**
     * @return array{thread?: array<string, mixed>, errors?: array<string, string>, created?: bool}
     */
    public function startDirectThread(AuthenticatedUser $actor, int $targetUserId): array
    {
        if (!$this->canUseInternalChat($actor)) {
            return ['errors' => ['general' => 'Seu usuário não está liberado para o chat interno.']];
        }

        if ($targetUserId <= 0) {
            return ['errors' => ['user_id' => 'Selecione um usuário válido.']];
        }

        if ($targetUserId === $actor->id) {
            return ['errors' => ['user_id' => 'Não é possível iniciar um chat consigo mesmo.']];
        }

        $targetUser = $this->users->find($targetUserId);
        if ($targetUser === null || ($targetUser['status'] ?? '') !== 'active') {
            return ['errors' => ['user_id' => 'Colaborador não encontrado ou inativo.']];
        }

        if (!$this->userAllowsInternalChat($targetUser)) {
            return ['errors' => ['user_id' => 'Este colaborador não está habilitado para o chat interno.']];
        }

        $existing = $this->threads->findDirectThread($actor->id, $targetUserId);
        if ($existing !== null) {
            $participants = $this->threads->participantsWithProfiles((int)$existing['id']);
            $existing['participants'] = $participants;
            $existing['display_name'] = $this->resolveThreadName($existing, $participants, $actor->id);

            return ['thread' => $existing, 'created' => false];
        }

        $threadId = $this->threads->create([
            'type' => 'direct',
            'subject' => null,
            'created_by' => $actor->id,
        ]);

        $this->threads->ensureParticipant($threadId, $actor->id, 'owner');
        $this->threads->ensureParticipant($threadId, $targetUserId, 'member');

        $thread = $this->loadThread($threadId, $actor);

        return ['thread' => $thread, 'created' => true];
    }

    /**
     * @param array<int|string> $rawParticipantIds
     * @return array{thread?: array<string, mixed>, errors?: array<string, string>, created?: bool}
     */
    public function createGroupThread(AuthenticatedUser $actor, string $subject, array $rawParticipantIds): array
    {
        if (!$this->canUseInternalChat($actor)) {
            return ['errors' => ['general' => 'Seu usuário não está liberado para o chat interno.']];
        }

        $cleanSubject = trim($subject);
        if (mb_strlen($cleanSubject, 'UTF-8') < 3) {
            return ['errors' => ['subject' => 'Informe um nome com ao menos 3 caracteres.']];
        }

        $participantIds = $this->normalizeParticipantIds($rawParticipantIds, $actor->id);
        if (count($participantIds) < 2) {
            return ['errors' => ['participants' => 'Selecione pelo menos dois colaboradores além de você.']];
        }

        $validIds = [];
        foreach ($participantIds as $participantId) {
            $user = $this->users->find($participantId);
            if ($user !== null && ($user['status'] ?? '') === 'active' && $this->userAllowsInternalChat($user)) {
                $validIds[] = $participantId;
            }
        }

        if (count($validIds) < 2) {
            return ['errors' => ['participants' => 'Pelo menos dois participantes precisam estar ativos.']];
        }

        $threadId = $this->threads->create([
            'type' => 'group',
            'subject' => $cleanSubject,
            'created_by' => $actor->id,
        ]);

        $this->threads->ensureParticipant($threadId, $actor->id, 'owner');
        foreach ($validIds as $participantId) {
            $this->threads->ensureParticipant($threadId, $participantId, 'member');
        }

        $thread = $this->loadThread($threadId, $actor);

        return ['thread' => $thread, 'created' => true];
    }

    /**
     * @return array{message?: array<string, mixed>, errors?: array<string, string>}
     */
    public function sendMessage(int $threadId, string $rawBody, AuthenticatedUser $actor): array
    {
        if (!$this->canUseInternalChat($actor)) {
            return ['errors' => ['thread' => 'Seu usuário não está liberado para o chat interno.']];
        }

        $trimmed = trim($rawBody);
        if ($trimmed === '') {
            return ['errors' => ['body' => 'Digite uma mensagem.']];
        }

        $thread = $this->threads->find($threadId);
        if ($thread === null) {
            return ['errors' => ['thread' => 'Conversa não encontrada.']];
        }

        $participant = $this->threads->participantRecord($threadId, $actor->id);
        if ($participant === null) {
            return ['errors' => ['thread' => 'Você não participa desta conversa.']];
        }

        if (($thread['status'] ?? 'open') === 'closed') {
            return ['errors' => ['thread' => 'Esta conversa foi finalizada.']];
        }

        if (($thread['type'] ?? '') === 'external' && !$this->canHandleExternalLeads($actor)) {
            return ['errors' => ['thread' => 'Seu usuário não pode responder este atendimento externo.']];
        }

        $this->autoClaimLeadOnFirstReply($thread, $actor);

        $body = mb_substr($trimmed, 0, self::MAX_MESSAGE_LENGTH, 'UTF-8');

        $messageId = $this->messages->create([
            'thread_id' => $threadId,
            'author_id' => $actor->id,
            'body' => $body,
            'external_author' => null,
            'is_system' => 0,
        ]);

        $message = $this->messages->find($messageId);
        $timestamp = $message['created_at'] ?? now();
        $this->threads->touchLastMessage($threadId, $messageId, $timestamp);
        $this->threads->markAsRead($threadId, $actor->id, $messageId, $timestamp);

        return ['message' => $message];
    }

    public function markThreadRead(int $threadId, int $messageId, AuthenticatedUser $actor): bool
    {
        if (!$this->canUseInternalChat($actor)) {
            return false;
        }

        $participant = $this->threads->participantRecord($threadId, $actor->id);
        if ($participant === null) {
            return false;
        }

        $current = $participant['last_read_message_id'] ?? 0;
        if ($messageId <= 0 || $messageId <= $current) {
            return false;
        }

        $this->threads->markAsRead($threadId, $actor->id, $messageId, now());
        return true;
    }

    /**
     * @return array{deleted?: int, threads?: int[], errors?: array<string, string>}
     */
    public function purgeMessages(int $cutoffTimestamp, AuthenticatedUser $actor): array
    {
        if (!$actor->isAdmin()) {
            return ['errors' => ['general' => 'Somente administradores podem limpar mensagens.']];
        }

        if ($cutoffTimestamp <= 0) {
            return ['errors' => ['cutoff' => 'Data limite inválida.']];
        }

        $result = $this->messages->purgeBefore($cutoffTimestamp);
        foreach ($result['threads'] as $threadId) {
            $this->threads->refreshLastMessageMetadata($threadId);
        }

        $this->messages->recordPurge($actor->id, $cutoffTimestamp, $result['deleted']);

        return ['deleted' => $result['deleted'], 'threads' => $result['threads']];
    }

    /**
     * @param array<string, mixed> $thread
     * @param array<int, array<string, mixed>> $participants
     */
    private function resolveThreadName(array $thread, array $participants, int $currentUserId): string
    {
        $subject = trim((string)($thread['subject'] ?? ''));
        if ($subject !== '') {
            return $subject;
        }

        if (($thread['type'] ?? 'direct') !== 'direct') {
            return 'Conversa #' . (int)($thread['id'] ?? 0);
        }

        foreach ($participants as $participant) {
            if ((int)($participant['user_id'] ?? 0) === $currentUserId) {
                continue;
            }

            $name = trim((string)($participant['name'] ?? ''));
            if ($name !== '') {
                return $name;
            }
        }

        return 'Chat #' . (int)($thread['id'] ?? 0);
    }

    private function previewBody(?string $body): string
    {
        if ($body === null || $body === '') {
            return '';
        }

        $singleLine = preg_replace('/\s+/', ' ', $body) ?? $body;
        $preview = mb_substr($singleLine, 0, 120, 'UTF-8');

        if (mb_strlen($singleLine, 'UTF-8') > 120) {
            $preview .= '…';
        }

        return $preview;
    }

    /**
     * @param array<string, string|null> $context
     * @return array{lead_id?: int, thread_id?: int, errors?: array<string, string>}
     */
    public function createExternalLead(string $fullName, string $ddd, string $phone, string $message, array $context = []): array
    {
        $adminRecipients = $this->filterExternalRecipients($this->users->listActiveAdmins());
        $activeRecipients = $this->filterExternalRecipients($this->users->listActiveForChat());

        $recipientIds = $adminRecipients;
        foreach ($activeRecipients as $candidateId) {
            if (!in_array($candidateId, $recipientIds, true)) {
                $recipientIds[] = $candidateId;
            }
        }

        if ($recipientIds === []) {
            return ['errors' => ['recipients' => 'Nenhum colaborador ativo está disponível para receber o atendimento.']];
        }

        $subject = sprintf('Lead externo · %s (%s %s)', $fullName, $ddd, $phone);
        $subject = mb_substr($subject, 0, 120, 'UTF-8');
        $creatorId = $recipientIds[0];

        $threadId = $this->threads->create([
            'type' => 'external',
            'subject' => $subject,
            'created_by' => $creatorId,
        ]);

        foreach ($recipientIds as $index => $userId) {
            $role = $index === 0 ? 'owner' : 'member';
            $this->threads->ensureParticipant($threadId, $userId, $role);
        }

        $body = "**Lead externo aguardando atendimento**" . PHP_EOL
            . 'Nome: ' . $fullName . PHP_EOL
            . 'Contato: (' . $ddd . ') ' . $phone . PHP_EOL
            . 'Origem: ' . ($context['source'] ?? 'landing') . PHP_EOL
            . PHP_EOL
            . "Mensagem:" . PHP_EOL
            . $message;

        $messageId = $this->messages->create([
            'thread_id' => $threadId,
            'author_id' => 0,
            'body' => $body,
            'is_system' => 1,
        ]);

        $messageRow = $this->messages->find($messageId);
        $timestamp = $messageRow['created_at'] ?? now();
        $this->threads->touchLastMessage($threadId, $messageId, $timestamp);

        $normalizedPhone = preg_replace('/\D+/', '', $phone) ?? $phone;

        $leadId = $this->externalLeads->create([
            'thread_id' => $threadId,
            'full_name' => $fullName,
            'ddd' => $ddd,
            'phone' => $phone,
            'normalized_phone' => $normalizedPhone,
            'message' => $message,
            'source' => $context['source'] ?? null,
            'status' => 'pending',
            'ip_address' => $context['ip'] ?? null,
            'user_agent' => $context['user_agent'] ?? null,
            'public_token' => bin2hex(random_bytes(16)),
        ]);

        $lead = $this->externalLeads->findByThread($threadId);

        return [
            'lead_id' => $leadId,
            'thread_id' => $threadId,
            'lead_token' => $lead['public_token'] ?? null,
        ];
    }

    /**
     * @return array{claimed?: bool, errors?: array<string, string>}
     */
    public function claimExternalLead(int $threadId, AuthenticatedUser $actor): array
    {
        if (!$this->canHandleExternalLeads($actor)) {
            return ['errors' => ['thread' => 'Seu usuário não pode assumir atendimentos externos.']];
        }

        $thread = $this->threads->find($threadId);
        if ($thread === null || ($thread['type'] ?? '') !== 'external') {
            return ['errors' => ['thread' => 'Conversa externa não encontrada.']];
        }

        if (($thread['status'] ?? 'open') === 'closed') {
            return ['errors' => ['thread' => 'Este atendimento já foi finalizado.']];
        }

        $participant = $this->threads->participantRecord($threadId, $actor->id);
        if ($participant === null && !$actor->isAdmin()) {
            return ['errors' => ['thread' => 'Você não participa desta conversa.']];
        }

        $lead = $this->externalLeads->findByThread($threadId);
        if ($lead === null) {
            return ['errors' => ['thread' => 'Lead externo não associado.']];
        }

        $claimedBy = (int)($lead['claimed_by'] ?? 0);
        if ($claimedBy > 0) {
            if ($claimedBy === $actor->id) {
                return ['claimed' => true];
            }

            $owner = $this->users->find($claimedBy);
            $ownerName = $owner['name'] ?? 'Outro agente';
            return ['errors' => ['thread' => sprintf('Atendimento já assumido por %s.', $ownerName)]];
        }

        $this->externalLeads->claim((int)$lead['id'], $actor->id);

        $messageId = $this->messages->create([
            'thread_id' => $threadId,
            'author_id' => 0,
            'body' => sprintf('Atendimento assumido por %s.', $actor->name),
            'is_system' => 1,
        ]);

        $message = $this->messages->find($messageId);
        $timestamp = $message['created_at'] ?? now();
        $this->threads->touchLastMessage($threadId, $messageId, $timestamp);

        return ['claimed' => true];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function externalLeadStatus(string $token): ?array
    {
        if ($token === '') {
            return null;
        }

        $lead = $this->externalLeads->findByToken($token);
        if ($lead === null) {
            return null;
        }

        $status = (string)($lead['status'] ?? 'pending');

        $agentName = null;
        if ($status !== 'closed' && !empty($lead['claimed_by'])) {
            $agent = $this->users->find((int)$lead['claimed_by']);
            if ($agent !== null) {
                $agentName = (string)($agent['name'] ?? null);
            }
        }

        return [
            'status' => $status,
            'agent_name' => $agentName,
            'claimed_at' => $lead['claimed_at'] ?? null,
            'thread_id' => (int)($lead['thread_id'] ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function externalMessages(string $token, ?int $afterId = null): ?array
    {
        $lead = $this->externalLeads->findByToken($token);
        if ($lead === null) {
            return null;
        }

        $threadId = (int)$lead['thread_id'];
        $status = strtolower((string)($lead['status'] ?? 'pending'));
        if ($status === 'closed') {
            return null;
        }

        $thread = $this->threads->find($threadId);
        if ($thread === null || ($thread['status'] ?? 'open') === 'closed') {
            return null;
        }

        $messages = $this->messages->listForThread($threadId, 80, null, $afterId);

        return [
            'lead' => $this->presentLeadForExternal($lead),
            'messages' => array_map(fn(array $message): array => $this->formatMessageForExternal($message), $messages),
            'thread_id' => $threadId,
        ];
    }

    /**
     * @return array{message?: array<string, mixed>, errors?: array<string, string>}
     */
    public function sendExternalMessage(string $token, string $rawBody, array $context = []): array
    {
        $trimmed = trim($rawBody);
        if ($trimmed === '') {
            return ['errors' => ['body' => 'Digite uma mensagem.']];
        }

        $lead = $this->externalLeads->findByToken($token);
        if ($lead === null) {
            return ['errors' => ['token' => 'Conversa não encontrada.']];
        }

        $threadId = (int)$lead['thread_id'];
        $thread = $this->threads->find($threadId);
        if ($thread === null) {
            return ['errors' => ['thread' => 'Esta conversa foi finalizada.']];
        }

        $status = strtolower((string)($lead['status'] ?? 'pending'));
        $threadStatus = strtolower((string)($thread['status'] ?? 'open'));

        if ($status === 'closed' || $threadStatus === 'closed') {
            $this->threads->reopen($threadId);
            $this->externalLeads->reopenLead((int)$lead['id']);
            $status = 'pending';
            $threadStatus = 'open';
        }

        $body = mb_substr($trimmed, 0, self::MAX_MESSAGE_LENGTH, 'UTF-8');
        $authorName = $lead['full_name'] ?? 'Visitante';

        $messageId = $this->messages->create([
            'thread_id' => $threadId,
            'author_id' => 0,
            'body' => $body,
            'external_author' => $authorName,
            'is_system' => 0,
        ]);

        $message = $this->messages->find($messageId);
        $timestamp = $message['created_at'] ?? now();
        $this->threads->touchLastMessage($threadId, $messageId, $timestamp);

        return ['message' => $this->formatMessageForExternal($message ?? [])];
    }

    /**
     * @return array{closed?: bool, errors?: array<string, string>}
     */
    public function closeThread(int $threadId, AuthenticatedUser $actor): array
    {
        if (!$this->canUseInternalChat($actor)) {
            return ['errors' => ['thread' => 'Seu usuário não está liberado para o chat interno.']];
        }

        $thread = $this->threads->find($threadId);
        if ($thread === null) {
            return ['errors' => ['thread' => 'Conversa não encontrada.']];
        }

        if ($this->threads->participantRecord($threadId, $actor->id) === null && !$actor->isAdmin()) {
            return ['errors' => ['thread' => 'Você não participa desta conversa.']];
        }

        $threadType = strtolower((string)($thread['type'] ?? 'direct'));

        if ($threadType !== 'external') {
            return ['errors' => ['thread' => 'Conversas internas não podem ser finalizadas.']];
        }

        if (($thread['status'] ?? 'open') === 'closed') {
            return ['closed' => true];
        }

        if (!$this->canHandleExternalLeads($actor)) {
            return ['errors' => ['thread' => 'Seu usuário não pode finalizar atendimentos externos.']];
        }

        $this->threads->close($threadId, $actor->id);

        $messageId = $this->messages->create([
            'thread_id' => $threadId,
            'author_id' => 0,
            'body' => sprintf('Conversa finalizada por %s.', $actor->name),
            'external_author' => null,
            'is_system' => 1,
        ]);

        $message = $this->messages->find($messageId);
        $timestamp = $message['created_at'] ?? now();
        $this->threads->touchLastMessage($threadId, $messageId, $timestamp);

        if ($threadType === 'external') {
            $lead = $this->externalLeads->findByThread($threadId);
            if ($lead !== null) {
                $this->externalLeads->closeLead((int)$lead['id'], $actor->id);
            }
        }

        return ['closed' => true];
    }

    /**
     * @param array<int, array<string, mixed>> $users
     * @return int[]
     */
    private function extractUserIds(array $users): array
    {
        $ids = [];
        foreach ($users as $user) {
            $id = (int)($user['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @param array<string, mixed>|null $lead
     * @return array<string, mixed>|null
     */
    private function enrichLead(?array $lead): ?array
    {
        if ($lead === null) {
            return null;
        }

        if (!empty($lead['claimed_by'])) {
            $agent = $this->users->find((int)$lead['claimed_by']);
            if ($agent !== null) {
                $lead['claimed_by_name'] = (string)($agent['name'] ?? '');
            }
        }

        return $lead;
    }

    /**
     * @param array<string, mixed> $thread
     */
    private function autoClaimLeadOnFirstReply(array $thread, AuthenticatedUser $actor): void
    {
        if (($thread['type'] ?? '') !== 'external') {
            return;
        }

        if (($thread['status'] ?? 'open') === 'closed') {
            return;
        }

        if (!$this->canHandleExternalLeads($actor)) {
            return;
        }

        $lead = $this->externalLeads->findByThread((int)($thread['id'] ?? 0));
        if ($lead === null) {
            return;
        }

        if ((int)($lead['claimed_by'] ?? 0) > 0) {
            return;
        }

        $this->claimExternalLead((int)$thread['id'], $actor);
    }

    /**
     * @param array<string, mixed> $lead
     * @return array<string, mixed>
     */
    private function presentLeadForExternal(array $lead): array
    {
        $agentName = null;
        if (!empty($lead['claimed_by'])) {
            $agent = $this->users->find((int)$lead['claimed_by']);
            if ($agent !== null) {
                $agentName = (string)($agent['name'] ?? null);
            }
        }

        return [
            'full_name' => $lead['full_name'] ?? 'Visitante',
            'status' => $lead['status'] ?? 'pending',
            'agent_name' => $agentName,
        ];
    }

    /**
     * @param array<string, mixed> $message
     * @return array<string, mixed>
     */
    private function formatMessageForExternal(array $message): array
    {
        $direction = 'agent';
        $author = $message['author_name'] ?? 'Equipe Selo ID';

        if (!empty($message['external_author'])) {
            $direction = 'visitor';
            $author = (string)$message['external_author'];
        } elseif ((int)($message['author_id'] ?? 0) === 0) {
            $direction = !empty($message['is_system']) ? 'system' : 'agent';
            $author = !empty($message['is_system']) ? 'Sistema' : $author;
        }

        return [
            'id' => (int)($message['id'] ?? 0),
            'body' => (string)($message['body'] ?? ''),
            'direction' => $direction,
            'author' => $author,
            'created_at' => (int)($message['created_at'] ?? now()),
        ];
    }

    /**
     * @return array<string, int>
     */
    public function retentionSettings(): array
    {
        return [
            'internal_days' => max(0, (int)$this->settings->get('chat.retention.internal_days', 0)),
            'external_days' => max(0, (int)$this->settings->get('chat.retention.external_days', 0)),
            'last_cleanup_at' => (int)$this->settings->get('chat.retention.last_cleanup_at', 0),
        ];
    }

    /**
     * @return array{updated?: bool, errors?: array<string, string>}
     */
    public function updateRetentionPolicies(AuthenticatedUser $actor, int $internalDays, int $externalDays): array
    {
        if (!$actor->isAdmin()) {
            return ['errors' => ['general' => 'Somente administradores podem alterar esta configuração.']];
        }

        $internalDays = max(0, $internalDays);
        $externalDays = max(0, $externalDays);

        $this->settings->setMany([
            'chat.retention.internal_days' => $internalDays,
            'chat.retention.external_days' => $externalDays,
        ]);

        $this->enforceRetentionPolicies(true);

        return ['updated' => true];
    }

    /**
     * @return array{deleted?: int, threads?: int[], skipped?: bool}
     */
    public function enforceRetentionPolicies(bool $force = false): array
    {
        $settings = $this->retentionSettings();
        $now = now();
        $lastRun = (int)$settings['last_cleanup_at'];

        if (!$force && $lastRun > 0 && ($now - $lastRun) < 3600) {
            return ['skipped' => true];
        }

        $totalDeleted = 0;
        $touchedThreads = [];

        if ($settings['external_days'] > 0) {
            $cutoff = $now - ($settings['external_days'] * 86400);
            $result = $this->messages->purgeByThreadTypesBefore(['external'], $cutoff);
            $totalDeleted += $result['deleted'];
            $touchedThreads = array_merge($touchedThreads, $result['threads']);
        }

        if ($settings['internal_days'] > 0) {
            $cutoff = $now - ($settings['internal_days'] * 86400);
            $result = $this->messages->purgeByThreadTypesBefore(['direct', 'group'], $cutoff);
            $totalDeleted += $result['deleted'];
            $touchedThreads = array_merge($touchedThreads, $result['threads']);
        }

        $touchedThreads = array_values(array_unique(array_map('intval', $touchedThreads)));
        foreach ($touchedThreads as $threadId) {
            $this->threads->refreshLastMessageMetadata($threadId);
        }

        $this->settings->set('chat.retention.last_cleanup_at', $now);

        return [
            'deleted' => $totalDeleted,
            'threads' => $touchedThreads,
        ];
    }

    /**
     * @param array<int|string> $rawIds
     * @return int[]
     */
    private function normalizeParticipantIds(array $rawIds, int $actorId): array
    {
        $unique = [];

        foreach ($rawIds as $value) {
            if (is_string($value)) {
                $value = trim($value);
            }

            if ($value === '' || $value === null) {
                continue;
            }

            if (!is_numeric($value)) {
                continue;
            }

            $id = (int)$value;
            if ($id <= 0 || $id === $actorId) {
                continue;
            }

            $unique[$id] = $id;
        }

        return array_values($unique);
    }
}
