<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Repositories\AppointmentRepository;
use App\Repositories\AvpAccessRepository;
use App\Repositories\AvpScheduleRepository;
use App\Repositories\UserRepository;
use App\Services\AppointmentService;
use App\Services\AvpAvailabilityService;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

final class AgendaController
{
    public function index(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return new RedirectResponse(url('auth/login'));
        }

        $isStandalone = (string)$request->query->get('standalone', '0') !== '0';

        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $referenceInput = (string)$request->query->get('date', '');
        $reference = $referenceInput !== ''
            ? DateTimeImmutable::createFromFormat('Y-m-d', $referenceInput, $timezone)
            : new DateTimeImmutable('today', $timezone);
        if ($reference === false) {
            $reference = new DateTimeImmutable('today', $timezone);
        }

        $rangeInput = strtolower((string)$request->query->get('range', 'week'));
        $allowedRanges = ['day', 'week', 'month', 'year'];
        if (!in_array($rangeInput, $allowedRanges, true)) {
            $rangeInput = 'week';
        }

        $referenceDate = $reference->setTime(0, 0, 0);
        $rangeStartDate = $referenceDate;
        $rangeEndDate = $referenceDate->setTime(23, 59, 59);

        switch ($rangeInput) {
            case 'day':
                $rangeStartDate = $referenceDate;
                $rangeEndDate = $referenceDate->setTime(23, 59, 59);
                break;
            case 'month':
                $rangeStartDate = $referenceDate->modify('first day of this month')->setTime(0, 0, 0);
                $rangeEndDate = $rangeStartDate
                    ->modify('first day of next month')
                    ->sub(new DateInterval('PT1S'));
                break;
            case 'year':
                $rangeStartDate = $referenceDate
                    ->setDate((int)$referenceDate->format('Y'), 1, 1)
                    ->setTime(0, 0, 0);
                $rangeEndDate = $rangeStartDate
                    ->modify('first day of january next year')
                    ->sub(new DateInterval('PT1S'));
                break;
            case 'week':
            default:
                $rangeStartDate = $referenceDate->modify('monday this week');
                if ((int)$rangeStartDate->format('N') !== 1) {
                    $rangeStartDate = $referenceDate->modify('this week monday');
                }
                $rangeStartDate = $rangeStartDate->setTime(0, 0, 0);
                $rangeEndDate = $rangeStartDate->add(new DateInterval('P6D'))->setTime(23, 59, 59);
                break;
        }

        $rangeLabels = [
            'day' => 'dia',
            'week' => 'semana',
            'month' => 'mês',
            'year' => 'ano',
        ];
        $rangeLabel = $rangeLabels[$rangeInput] ?? 'semana';

        $appointmentRepo = new AppointmentRepository();
        $scheduleRepo = new AvpScheduleRepository();
        $userRepo = new UserRepository();
        $avpAccessRepo = new AvpAccessRepository();

        $avpOptions = $userRepo->listAvps();
        $accessContext = $this->resolveAccessibleAvps($authUser, $avpOptions, $avpAccessRepo);
        $avpOptions = $accessContext['list'];
        $accessibleAvpIds = $accessContext['ids'];
        $hasFullAgendaAccess = $accessContext['full'];
        $avpIndex = [];
        foreach ($avpOptions as $option) {
            $avpIndex[(int)$option['id']] = $option;
        }

        $canSwitchAvp = $hasFullAgendaAccess || count($avpOptions) > 1;
        $requestedAvpId = (int)$request->query->get('avp', 0);
        $selectedAvpId = null;

        if ($requestedAvpId > 0 && in_array($requestedAvpId, $accessibleAvpIds, true)) {
            $selectedAvpId = $requestedAvpId;
        } elseif ($hasFullAgendaAccess && $requestedAvpId === 0) {
            $selectedAvpId = null;
        } elseif ($authUser->isAvp && in_array($authUser->id, $accessibleAvpIds, true)) {
            $selectedAvpId = $authUser->id;
        } elseif ($accessibleAvpIds !== []) {
            $selectedAvpId = $accessibleAvpIds[0];
        } elseif (!$hasFullAgendaAccess && $authUser->isAdmin()) {
            $selectedAvpId = null;
        }

        $defaultOwnerId = $selectedAvpId;
        if ($defaultOwnerId === null) {
            if (in_array($authUser->id, $accessibleAvpIds, true)) {
                $defaultOwnerId = $authUser->id;
            } elseif ($accessibleAvpIds !== []) {
                $defaultOwnerId = $accessibleAvpIds[0];
            } elseif (!empty($avpOptions)) {
                $defaultOwnerId = (int)$avpOptions[0]['id'];
            } elseif ($authUser->isAvp) {
                $defaultOwnerId = $authUser->id;
            } else {
                $defaultOwnerId = 0;
            }
        }

        $baseManagePermission = $authUser->isAdmin() || $authUser->can('crm.agenda.manage') || $authUser->isAvp;
        $canManageAgenda = $baseManagePermission && ($hasFullAgendaAccess || $accessibleAvpIds !== []);

        $rangeStart = $rangeStartDate->getTimestamp();
        $rangeEnd = $rangeEndDate->getTimestamp();

        $appointments = [];
        if ($selectedAvpId !== null) {
            $appointments = $appointmentRepo->listForOwner($selectedAvpId, $rangeStart, $rangeEnd);
        } else {
            if (!$hasFullAgendaAccess && $accessibleAvpIds === []) {
                $appointments = [];
            } else {
                $ownerFilter = $hasFullAgendaAccess ? null : $accessibleAvpIds;
                $appointments = $appointmentRepo->listWithinRange($rangeStart, $rangeEnd, $ownerFilter);
            }
        }

        $ownerLookup = [];
        foreach ($avpOptions as $option) {
            $ownerLookup[(int)$option['id']] = (string)$option['name'];
        }

        $appointmentsByDay = [];
        foreach ($appointments as $item) {
            $ownerId = (int)($item['owner_user_id'] ?? 0);
            if ($ownerId > 0 && !isset($ownerLookup[$ownerId])) {
                $owner = $userRepo->find($ownerId);
                if ($owner !== null) {
                    $ownerLookup[$ownerId] = (string)($owner['name'] ?? 'Colaborador #' . $ownerId);
                }
            }

            $startTs = (int)($item['starts_at'] ?? 0);
            if ($startTs <= 0) {
                continue;
            }

            $dayKey = (new DateTimeImmutable('@' . $startTs))
                ->setTimezone($timezone)
                ->format('Y-m-d');

            $item['owner_name'] = $ownerLookup[$ownerId] ?? '—';
            $appointmentsByDay[$dayKey][] = $item;
        }

        ksort($appointmentsByDay);
        foreach ($appointmentsByDay as &$dayItems) {
            usort($dayItems, static fn(array $a, array $b): int => (($a['starts_at'] ?? 0) <=> ($b['starts_at'] ?? 0)));
        }
        unset($dayItems);

        $scheduleMatrix = $scheduleRepo->scheduleMatrixForUser($authUser->id);

        $prefillAppointment = [
            'client_name' => trim((string)$request->query->get('prefill_client_name', '')),
            'client_document' => trim((string)$request->query->get('prefill_client_document', '')),
            'auto_open' => (int)$request->query->get('schedule', 0) === 1,
        ];
        if ($prefillAppointment['client_name'] === '' && $prefillAppointment['client_document'] === '') {
            $prefillAppointment['auto_open'] = false;
        }

        return view('crm/agenda/index', [
            'weekStart' => $rangeStartDate,
            'weekEnd' => $rangeEndDate,
            'rangeStart' => $rangeStartDate,
            'rangeEnd' => $rangeEndDate,
            'rangeType' => $rangeInput,
            'rangeLabel' => $rangeLabel,
            'referenceDate' => $referenceDate,
            'appointmentsByDay' => $appointmentsByDay,
            'scheduleMatrix' => $scheduleMatrix,
            'avpOptions' => $avpOptions,
            'currentUser' => $authUser,
            'selectedAvpId' => $selectedAvpId,
            'canSwitchAvp' => $canSwitchAvp,
            'canManageAgenda' => $canManageAgenda,
            'hasFullAgendaAccess' => $hasFullAgendaAccess,
            'defaultOwnerId' => $defaultOwnerId,
            'prefillAppointment' => $prefillAppointment,
            'standalone' => $isStandalone,
        ]);
    }

    public function publicView(Request $request): Response
    {
        $params = $request->query->all();
        $params['standalone'] = '1';
        $queryString = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $target = url('agenda') . ($queryString !== '' ? '?' . $queryString : '?standalone=1');

        return new RedirectResponse($target);
    }

    public function availability(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return new JsonResponse(['error' => 'Não autenticado.'], 401);
        }

        $avpUserId = (int)$request->query->get('avp_user_id', 0);
        if ($avpUserId <= 0) {
            $avpUserId = (int)$request->query->get('avp', 0);
        }
        $dateParam = trim((string)$request->query->get('date', ''));

        if ($avpUserId <= 0 || $dateParam === '') {
            return new JsonResponse(['error' => 'Parâmetros inválidos.'], 422);
        }

        $userRepo = new UserRepository();
        $avpRecord = $userRepo->find($avpUserId);
        if ($avpRecord === null || (int)($avpRecord['is_avp'] ?? 0) !== 1) {
            return new JsonResponse(['error' => 'AVP não encontrado.'], 404);
        }

        $avpAccessRepo = new AvpAccessRepository();
        if (!$this->userHasAccessToAvp($authUser, $avpUserId, $avpRecord, $avpAccessRepo)) {
            return new JsonResponse(['error' => 'Sem permissão para consultar esta agenda.'], 403);
        }

        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));
        $date = DateTimeImmutable::createFromFormat('Y-m-d', $dateParam, $timezone);
        if ($date === false) {
            return new JsonResponse(['error' => 'Data inválida.'], 422);
        }

        $service = new AvpAvailabilityService(null, null, $timezone);
        $slots = $service->slotsForDate($avpUserId, $date);

        return new JsonResponse(['slots' => $slots]);
    }

    public function storeAppointment(Request $request, array $params = []): Response
    {
        $authUser = $this->currentUser($request);
        if ($authUser === null) {
            return $this->json(['error' => 'Não autenticado.'], 401);
        }

        $service = new AppointmentService();
        $payload = $this->requestPayload($request);
        $result = $service->create($payload, $authUser);

        if (isset($result['errors'])) {
            return $this->json(['errors' => $result['errors']], $this->statusForErrors($result['errors']));
        }

        return $this->json(['appointment' => $result['appointment']], 201);
    }

    public function updateAppointment(Request $request, array $params = []): Response
    {
        $authUser = $this->currentUser($request);
        if ($authUser === null) {
            return $this->json(['error' => 'Não autenticado.'], 401);
        }

        $appointmentId = isset($params['id']) ? (int)$params['id'] : 0;
        if ($appointmentId <= 0) {
            return $this->json(['error' => 'Compromisso inválido.'], 404);
        }

        $service = new AppointmentService();
        $payload = $this->requestPayload($request);
        $result = $service->update($appointmentId, $payload, $authUser);

        if (isset($result['errors'])) {
            return $this->json(['errors' => $result['errors']], $this->statusForErrors($result['errors']));
        }

        return $this->json(['appointment' => $result['appointment']], 200);
    }

    public function deleteAppointment(Request $request, array $params = []): Response
    {
        $authUser = $this->currentUser($request);
        if ($authUser === null) {
            return $this->json(['error' => 'Não autenticado.'], 401);
        }

        $appointmentId = isset($params['id']) ? (int)$params['id'] : 0;
        if ($appointmentId <= 0) {
            return $this->json(['error' => 'Compromisso inválido.'], 404);
        }

        $service = new AppointmentService();
        $result = $service->delete($appointmentId, $authUser);

        if (isset($result['errors'])) {
            return $this->json(['errors' => $result['errors']], $this->statusForErrors($result['errors']));
        }

        return $this->json(['deleted' => true], 200);
    }

    public function updateConfig(Request $request): Response
    {
        $authUser = $request->attributes->get('user');
        if (!$authUser instanceof AuthenticatedUser) {
            return new RedirectResponse(url('auth/login'));
        }

        $data = $request->request->all('config');
        $scheduleRepo = new AvpScheduleRepository();
        $timezone = new DateTimeZone(config('app.timezone', 'America/Sao_Paulo'));

        foreach ($data as $day => $row) {
            $dayIndex = (int)$day;
            if ($dayIndex < 0 || $dayIndex > 6) {
                continue;
            }

            $start = $this->toMinutes($row['start'] ?? null);
            $end = $this->toMinutes($row['end'] ?? null);
            $lunchStart = $this->toMinutes($row['lunch_start'] ?? null);
            $lunchEnd = $this->toMinutes($row['lunch_end'] ?? null);
            $slot = max(20, (int)($row['slot'] ?? 20));
            $isClosed = !empty($row['is_closed']);

            if ($start === null || $end === null || $end <= $start) {
                continue;
            }

            $offlineBlocks = $this->normalizeOfflineBlocks((string)($row['offline'] ?? ''));

            $scheduleRepo->upsert([
                'user_id' => $authUser->id,
                'day_of_week' => $dayIndex,
                'slot_duration_minutes' => $slot,
                'work_start_minutes' => $start,
                'work_end_minutes' => $end,
                'lunch_start_minutes' => $lunchStart,
                'lunch_end_minutes' => $lunchEnd,
                'offline_blocks' => $offlineBlocks !== [] ? json_encode($offlineBlocks) : null,
                'is_closed' => $isClosed ? 1 : 0,
            ]);
        }

        $_SESSION['agenda_feedback'] = [
            'type' => 'success',
            'message' => 'Agenda configurada com sucesso.',
        ];

        return new RedirectResponse(url('agenda'));
    }

    private function toMinutes(?string $value): ?int
    {
        $value = trim((string)$value);
        if ($value === '') {
            return null;
        }

        if (!preg_match('/^(\d{2}):(\d{2})$/', $value, $matches)) {
            return null;
        }

        $hours = (int)$matches[1];
        $minutes = (int)$matches[2];

        return ($hours * 60) + $minutes;
    }

    private function normalizeOfflineBlocks(string $input): array
    {
        $input = trim($input);
        if ($input === '') {
            return [];
        }

        $blocks = [];
        foreach (preg_split('/\r?\n/', $input) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            $parts = array_map('trim', explode('-', $line));
            if (count($parts) !== 2) {
                continue;
            }

            $start = $this->toMinutes($parts[0]);
            $end = $this->toMinutes($parts[1]);
            if ($start === null || $end === null || $end <= $start) {
                continue;
            }

            $blocks[] = ['start' => $start, 'end' => $end];
        }

        return $blocks;
    }

    private function currentUser(Request $request): ?AuthenticatedUser
    {
        $authUser = $request->attributes->get('user');
        return $authUser instanceof AuthenticatedUser ? $authUser : null;
    }

    private function requestPayload(Request $request): array
    {
        $payload = $request->request->all();
        if ($payload !== []) {
            return $payload;
        }

        $content = trim((string)$request->getContent());
        if ($content === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function json(array $payload, int $status = 200): JsonResponse
    {
        return new JsonResponse($payload, $status);
    }

    private function statusForErrors(array $errors): int
    {
        // Surface permission and not-found scenarios with meaningful HTTP codes.
        $general = isset($errors['general']) ? strtolower((string)$errors['general']) : '';
        if ($general !== '') {
            if (str_contains($general, 'não encontrado')) {
                return 404;
            }
            if (str_contains($general, 'permiss')) {
                return 403;
            }
        }

        return 422;
    }

    /**
     * @return array{list: array<int, array{id:int,name:string,email:string,role:string,avp_identity_label:?string,avp_identity_cpf:?string}>, ids: int[], full: bool}
     */
    private function resolveAccessibleAvps(AuthenticatedUser $user, array $avpOptions, AvpAccessRepository $accessRepo): array
    {
        $fullAccess = $user->isAdmin() || $user->clientAccessScope !== 'custom';
        $avpList = $avpOptions;
        $ids = array_map(static fn(array $item): int => (int)$item['id'], $avpOptions);

        if ($fullAccess) {
            if ($user->isAvp && !in_array($user->id, $ids, true)) {
                $avpList[] = $this->fallbackAvpRecord($user);
                $ids[] = $user->id;
            }

            return [
                'list' => array_values($avpList),
                'ids' => array_values(array_unique($ids)),
                'full' => true,
            ];
        }

        $filters = $accessRepo->listForUser($user->id);
        $allowedNames = [];
        $allowedCpfs = [];

        foreach ($filters as $filter) {
            $name = $this->normalizeAvpName($filter['normalized_name'] ?? $filter['label'] ?? '');
            if ($name !== '') {
                $allowedNames[$name] = true;
            }

            $cpf = $this->digitsOrNull($filter['avp_cpf'] ?? null);
            if ($cpf !== null) {
                $allowedCpfs[$cpf] = true;
            }
        }

        if ($user->isAvp) {
            $selfName = $this->normalizeAvpName($user->avpIdentityLabel ?? $user->name);
            if ($selfName !== '') {
                $allowedNames[$selfName] = true;
            }

            $selfCpf = $this->digitsOrNull($user->avpIdentityCpf ?? $user->cpf);
            if ($selfCpf !== null) {
                $allowedCpfs[$selfCpf] = true;
            }
        }

        $filtered = [];
        $filteredIds = [];
        foreach ($avpOptions as $option) {
            $id = (int)$option['id'];
            $label = $option['avp_identity_label'] ?? $option['name'];
            $normalized = $this->normalizeAvpName($label);
            $cpf = $this->digitsOrNull($option['avp_identity_cpf'] ?? null);

            $isAllowed = false;
            if ($cpf !== null && isset($allowedCpfs[$cpf])) {
                $isAllowed = true;
            } elseif ($normalized !== '' && isset($allowedNames[$normalized])) {
                $isAllowed = true;
            } elseif ($user->isAvp && $id === $user->id) {
                $isAllowed = true;
            }

            if ($isAllowed) {
                $filtered[] = $option;
                $filteredIds[] = $id;
            }
        }

        if ($user->isAvp && !in_array($user->id, $filteredIds, true)) {
            $filtered[] = $this->fallbackAvpRecord($user);
            $filteredIds[] = $user->id;
        }

        return [
            'list' => array_values($filtered),
            'ids' => array_values(array_unique($filteredIds)),
            'full' => false,
        ];
    }

    private function normalizeAvpName(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $trimmed = trim(mb_strtolower($value, 'UTF-8'));
        return $trimmed;
    }

    private function digitsOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (!is_scalar($value)) {
            return null;
        }

        $digits = preg_replace('/[^0-9]/', '', (string)$value);
        return $digits !== '' ? $digits : null;
    }

    private function fallbackAvpRecord(AuthenticatedUser $user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role,
            'avp_identity_label' => $user->avpIdentityLabel,
            'avp_identity_cpf' => $user->avpIdentityCpf,
        ];
    }

    private function userHasAccessToAvp(
        AuthenticatedUser $user,
        int $avpUserId,
        array $avpRecord,
        AvpAccessRepository $accessRepo
    ): bool {
        if ($user->isAdmin() || $user->id === $avpUserId) {
            return true;
        }

        if ($user->clientAccessScope !== 'custom') {
            return true;
        }

        $filters = $accessRepo->listForUser($user->id);
        if ($filters === []) {
            return false;
        }

        $targetName = $this->normalizeAvpName($avpRecord['avp_identity_label'] ?? $avpRecord['name'] ?? '');
        if ($targetName === '' && isset($avpRecord['name'])) {
            $targetName = $this->normalizeAvpName((string)$avpRecord['name']);
        }
        $targetCpf = $this->digitsOrNull($avpRecord['avp_identity_cpf'] ?? ($avpRecord['cpf'] ?? null));

        foreach ($filters as $filter) {
            $filterCpf = $this->digitsOrNull($filter['avp_cpf'] ?? null);
            if ($filterCpf !== null && $targetCpf !== null && $filterCpf === $targetCpf) {
                return true;
            }

            $filterName = $this->normalizeAvpName($filter['normalized_name'] ?? $filter['label'] ?? '');
            if ($filterName !== '' && $filterName === $targetName) {
                return true;
            }
        }

        return false;
    }
}
