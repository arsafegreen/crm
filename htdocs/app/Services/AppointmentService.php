<?php

declare(strict_types=1);

namespace App\Services;

use App\Auth\AuthenticatedUser;
use App\Repositories\AppointmentRepository;
use App\Repositories\AvpAccessRepository;
use App\Repositories\CalendarPermissionRepository;
use App\Repositories\UserRepository;
use DateTimeImmutable;
use DateTimeZone;

final class AppointmentService
{
    private const VALID_STATUSES = ['scheduled', 'confirmed', 'done', 'completed', 'canceled', 'cancelled'];

    private AppointmentRepository $appointments;
    private CalendarPermissionRepository $calendarPermissions;
    private AvpAccessRepository $avpAccess;
    private UserRepository $users;
    private DateTimeZone $timezone;

    public function __construct(
        ?AppointmentRepository $appointments = null,
        ?CalendarPermissionRepository $calendarPermissions = null,
        ?AvpAccessRepository $avpAccess = null,
        ?UserRepository $users = null,
        ?DateTimeZone $timezone = null
    ) {
        $this->appointments = $appointments ?? new AppointmentRepository();
        $this->calendarPermissions = $calendarPermissions ?? new CalendarPermissionRepository();
        $this->avpAccess = $avpAccess ?? new AvpAccessRepository();
        $this->users = $users ?? new UserRepository();
        $this->timezone = $timezone ?? new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
    }

    /**
     * @return array{appointment?: array<string, mixed>, errors?: array<string, string>}
     */
    public function create(array $input, AuthenticatedUser $actor): array
    {
        [$payload, $errors] = $this->preparePayload($input, $actor, null, 'create');
        if ($errors !== []) {
            return ['errors' => $errors];
        }

        $appointmentId = $this->appointments->create($payload);
        $participantIds = $this->normalizeParticipants($input['participants'] ?? null);
        if ($participantIds !== null) {
            $this->appointments->syncParticipants($appointmentId, $participantIds);
        }

        return ['appointment' => $this->appointments->find($appointmentId)];
    }

    /**
     * @return array{appointment?: array<string, mixed>, errors?: array<string, string>}
     */
    public function update(int $appointmentId, array $input, AuthenticatedUser $actor): array
    {
        $existing = $this->appointments->find($appointmentId);
        if ($existing === null) {
            return ['errors' => ['general' => 'Compromisso não encontrado.']];
        }

        [$payload, $errors] = $this->preparePayload($input, $actor, $existing, 'edit');
        if ($errors !== []) {
            return ['errors' => $errors];
        }

        if ($payload !== []) {
            $this->appointments->update($appointmentId, $payload);
        }

        if (array_key_exists('participants', $input)) {
            $participantIds = $this->normalizeParticipants($input['participants']);
            if ($participantIds !== null) {
                $this->appointments->syncParticipants($appointmentId, $participantIds);
            }
        }

        return ['appointment' => $this->appointments->find($appointmentId)];
    }

    /**
     * @return array{deleted?: bool, errors?: array<string, string>}
     */
    public function delete(int $appointmentId, AuthenticatedUser $actor): array
    {
        $existing = $this->appointments->find($appointmentId);
        if ($existing === null) {
            return ['errors' => ['general' => 'Compromisso não encontrado.']];
        }

        $ownerId = (int)($existing['owner_user_id'] ?? 0);
        if (!$this->canManage($actor, $ownerId, 'cancel')) {
            return ['errors' => ['general' => 'Você não tem permissão para remover este compromisso.']];
        }

        $this->appointments->delete($appointmentId);

        return ['deleted' => true];
    }

    /**
     * @return array{array<string, mixed>, array<string, string>}
     */
    private function preparePayload(array $input, AuthenticatedUser $actor, ?array $existing, string $action): array
    {
        $errors = [];
        $payload = [];

        $ownerId = (int)($input['owner_user_id'] ?? ($existing['owner_user_id'] ?? $actor->id));
        if ($ownerId <= 0) {
            $errors['owner_user_id'] = 'Informe o colaborador responsável pela agenda.';
        } elseif (!$this->canManage($actor, $ownerId, $action === 'create' ? 'create' : 'edit')) {
            $errors['owner_user_id'] = 'Você não pode gerenciar a agenda selecionada.';
        } else {
            $ownerRecord = $this->users->find($ownerId);
            if ($ownerRecord === null) {
                $errors['owner_user_id'] = 'Colaborador não encontrado.';
            } elseif ((int)($ownerRecord['is_avp'] ?? 0) !== 1 && !$actor->isAdmin()) {
                $errors['owner_user_id'] = 'Somente AVPs podem receber agendamentos.';
            } else {
                $payload['owner_user_id'] = $ownerId;
            }
        }

        $title = trim((string)($input['title'] ?? ($existing['title'] ?? '')));
        if ($title === '') {
            $errors['title'] = 'Informe um título para o compromisso.';
        } elseif (mb_strlen($title) < 3) {
            $errors['title'] = 'O título deve ter pelo menos 3 caracteres.';
        } else {
            $payload['title'] = $title;
        }

        $clientId = $this->toIntOrNull($input['client_id'] ?? null);
        if ($clientId !== null) {
            $payload['client_id'] = $clientId;
        }

        $clientName = trim((string)($input['client_name'] ?? ($existing['client_name'] ?? '')));
        if ($clientName !== '') {
            $payload['client_name'] = $clientName;
        }

        $clientDocument = $this->digitsOrNull($input['client_document'] ?? ($existing['client_document'] ?? null));
        if ($clientDocument !== null) {
            $payload['client_document'] = $clientDocument;
        }

        $payload['description'] = $input['description'] ?? ($existing['description'] ?? null);
        $payload['category'] = $this->sanitizeTag($input['category'] ?? ($existing['category'] ?? null));
        $payload['channel'] = $this->sanitizeTag($input['channel'] ?? ($existing['channel'] ?? null));
        $payload['location'] = $this->sanitizeTag($input['location'] ?? ($existing['location'] ?? null));

        $status = strtolower(trim((string)($input['status'] ?? ($existing['status'] ?? 'scheduled'))));
        if ($status !== '') {
            $payload['status'] = $this->normalizeStatus($status);
        }

        $allowConflicts = !empty($input['allow_conflicts']);
        $payload['allow_conflicts'] = $allowConflicts ? 1 : 0;

        $startsAt = $this->parseDateTime(
            $input['starts_at'] ?? null,
            $input['date'] ?? null,
            $input['start_time'] ?? null
        );
        $endsAt = $this->parseDateTime(
            $input['ends_at'] ?? null,
            $input['date'] ?? null,
            $input['end_time'] ?? null
        );

        if ($startsAt === null) {
            $errors['starts_at'] = 'Data/hora inicial inválida.';
        }

        if ($endsAt === null) {
            $errors['ends_at'] = 'Data/hora final inválida.';
        }

        if ($startsAt !== null && $endsAt !== null) {
            if ($endsAt <= $startsAt) {
                $errors['ends_at'] = 'O horário final deve ser maior que o inicial.';
            } else {
                $payload['starts_at'] = $startsAt;
                $payload['ends_at'] = $endsAt;
            }
        }

        if ($payload !== [] && isset($payload['owner_user_id'], $payload['starts_at'], $payload['ends_at']) && !$allowConflicts) {
            $ignoreId = $existing !== null ? (int)($existing['id'] ?? 0) : null;
            $conflicts = $this->appointments->busyIntervalsForOwner(
                (int)$payload['owner_user_id'],
                (int)$payload['starts_at'],
                (int)$payload['ends_at'],
                $ignoreId > 0 ? $ignoreId : null
            );

            if ($conflicts !== []) {
                $errors['conflict'] = 'Já existe compromisso no horário selecionado.';
            }
        }

        if ($existing === null) {
            $payload['created_by_user_id'] = $actor->id;
        }

        return [$payload, $errors];
    }

    private function canManage(AuthenticatedUser $actor, int $ownerUserId, string $scope): bool
    {
        if ($actor->isAdmin()) {
            return true;
        }

        if ($ownerUserId === $actor->id) {
            return true;
        }

        if ($this->hasClientScopeAccess($actor, $ownerUserId)) {
            return true;
        }

        $requiredScope = match ($scope) {
            'edit' => 'edit',
            'cancel' => 'cancel',
            default => 'create',
        };

        return $this->calendarPermissions->can($ownerUserId, $actor->id, $requiredScope);
    }

    private function hasClientScopeAccess(AuthenticatedUser $actor, int $ownerUserId): bool
    {
        if ($actor->clientAccessScope !== 'custom') {
            return true;
        }

        $filters = $this->avpAccess->listForUser($actor->id);
        if ($filters === []) {
            return false;
        }

        $owner = $this->users->find($ownerUserId);
        if ($owner === null) {
            return false;
        }

        $ownerCpf = $this->digitsOrNull($owner['avp_identity_cpf'] ?? ($owner['cpf'] ?? null));
        $ownerName = $this->normalizeAvpName($owner['avp_identity_label'] ?? $owner['name'] ?? '');

        foreach ($filters as $filter) {
            $filterCpf = $this->digitsOrNull($filter['avp_cpf'] ?? null);
            if ($filterCpf !== null && $ownerCpf !== null && $filterCpf === $ownerCpf) {
                return true;
            }

            $filterName = $this->normalizeAvpName($filter['normalized_name'] ?? $filter['label'] ?? '');
            if ($filterName !== '' && $filterName === $ownerName) {
                return true;
            }
        }

        return false;
    }

    private function normalizeStatus(string $status): string
    {
        if ($status === 'cancelled') {
            $status = 'canceled';
        }

        if (!in_array($status, self::VALID_STATUSES, true)) {
            return 'scheduled';
        }

        return $status;
    }

    private function parseDateTime(mixed $value, mixed $date, mixed $time): ?int
    {
        $candidate = $value;
        if (is_string($candidate)) {
            $candidate = trim($candidate);
        }

        if ($candidate !== null && $candidate !== '') {
            if (is_numeric($candidate)) {
                $timestamp = (int)$candidate;
                return $timestamp > 0 ? $timestamp : null;
            }

            try {
                $dt = new DateTimeImmutable((string)$candidate, $this->timezone);
                return $dt->getTimestamp();
            } catch (\Exception) {
                // ignore and fallback to date + time
            }
        }

        $dateString = is_string($date) ? trim($date) : '';
        $timeString = is_string($time) ? trim($time) : '';

        if ($dateString !== '' && $timeString !== '') {
            try {
                $dt = new DateTimeImmutable($dateString . ' ' . $timeString, $this->timezone);
                return $dt->getTimestamp();
            } catch (\Exception) {
                return null;
            }
        }

        return null;
    }

    private function digitsOrNull(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', (string)$value);
        return $digits !== '' ? $digits : null;
    }

    private function toIntOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (!is_numeric($value)) {
            return null;
        }

        $intValue = (int)$value;
        return $intValue > 0 ? $intValue : null;
    }

    private function sanitizeTag(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string)$value);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, 120);
    }

    /**
     * @return int[]|null
     */
    private function normalizeParticipants(mixed $value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (!is_array($value)) {
            return [];
        }

        $unique = [];
        foreach ($value as $item) {
            $id = $this->toIntOrNull($item);
            if ($id !== null && $id > 0) {
                $unique[$id] = true;
            }
        }

        return array_keys($unique);
    }

    private function normalizeAvpName(mixed $value): string
    {
        if (!is_scalar($value)) {
            return '';
        }

        $normalized = trim(mb_strtolower((string)$value, 'UTF-8'));
        return $normalized;
    }
}
