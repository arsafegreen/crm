<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Auth\AuthenticatedUser;
use App\Repositories\RfbProspectRepository;
use App\Repositories\SettingRepository;
use App\Support\RfbWhatsappTemplates;
use App\Services\BaseRfbImportService;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class RfbBaseController
{
    public function index(Request $request): Response
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            return new RedirectResponse(url('auth/login'));
        }

        $repo = new RfbProspectRepository();
        $page = max(1, (int)$request->query->get('page', 1));
        $perPage = min(100, max(10, (int)$request->query->get('per_page', 50)));
        $filters = [
            'query' => (string)$request->query->get('query', ''),
            'state' => (string)$request->query->get('state', ''),
            'city' => (string)$request->query->get('city', ''),
            'cnae' => (string)$request->query->get('cnae', ''),
            'has_email' => (string)$request->query->get('has_email', ''),
            'has_whatsapp' => (string)$request->query->get('has_whatsapp', ''),
            'status' => (string)$request->query->get('status', 'active'),
        ];

        $filtersApplied = (string)$request->query->get('applied', '0') === '1';
        $hasFilters = $this->rfbFiltersActive($filters);
        $shouldQuery = $filtersApplied && $hasFilters;

        $items = [];
        $pagination = [
            'page' => $page,
            'per_page' => $perPage,
            'total' => 0,
            'pages' => 1,
        ];
        $filtersPayload = array_merge($filters, [
            'applied' => 0,
            'blocked' => $filtersApplied && !$hasFilters ? 1 : 0,
        ]);

        if ($shouldQuery) {
            $result = $repo->paginate($page, $perPage, $filters);
            $items = $result['data'];
            $pagination = $result['pagination'];
            $filtersPayload = array_merge($filtersPayload, $result['filters'], [
                'applied' => 1,
                'blocked' => 0,
            ]);
        }

        $stats = $shouldQuery ? $repo->stats() : null;
        $latest = $shouldQuery ? $repo->latestEntries() : [];

        $feedback = $_SESSION['rfb_upload_feedback'] ?? null;
        unset($_SESSION['rfb_upload_feedback']);

        $whatsappTemplates = $this->loadWhatsappTemplates();

        return view('rfb/index', [
            'items' => $items,
            'pagination' => $pagination,
            'filters' => $filtersPayload,
            'stats' => $stats,
            'latest' => $latest,
            'feedback' => $feedback,
            'perPage' => $perPage,
            'whatsappTemplates' => $whatsappTemplates,
        ]);
    }

    public function cityOptions(Request $request): Response
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            return new RedirectResponse(url('auth/login'));
        }

        $state = strtoupper(trim((string)$request->query->get('state', '')));
        $search = trim((string)$request->query->get('search', ''));
        $limit = (int)$request->query->get('limit', 200);
        $limit = max(25, min(500, $limit));

        $repo = new RfbProspectRepository();
        $items = $repo->listCities($state !== '' ? $state : null, $search, $limit);

        return $this->jsonResponse([
            'status' => 'success',
            'data' => [
                'type' => 'city',
                'items' => $items,
            ],
        ]);
    }

    public function cnaeOptions(Request $request): Response
    {
        $user = $request->attributes->get('user');
        if (!$user instanceof AuthenticatedUser) {
            return new RedirectResponse(url('auth/login'));
        }

        $search = trim((string)$request->query->get('search', ''));
        $limit = (int)$request->query->get('limit', 200);
        $limit = max(25, min(500, $limit));

        $repo = new RfbProspectRepository();
        $items = $repo->listCnaes($search, $limit);

        return $this->jsonResponse([
            'status' => 'success',
            'data' => [
                'type' => 'cnae',
                'items' => $items,
            ],
        ]);
    }

    public function updateStatus(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        if ($id < 1) {
            return new RedirectResponse(url('rfb-base'));
        }

        $action = (string)$request->request->get('action', '');
        $reason = (string)$request->request->get('reason', 'manual');
        $redirectQuery = trim((string)$request->request->get('redirect_query', ''));

        $repo = new RfbProspectRepository();

        try {
            if ($action === 'exclude') {
                if (!$repo->markExcluded($id, $reason)) {
                    throw new \RuntimeException('Registro não encontrado ou já excluído.');
                }

                $this->flash('success', 'Prospecto movido para a lista de exclusão.');
            } elseif ($action === 'restore') {
                if (!$repo->restore($id)) {
                    throw new \RuntimeException('Registro não encontrado para restauração.');
                }

                $this->flash('success', 'Prospecto restaurado para a base ativa.');
            } else {
                throw new \RuntimeException('Ação inválida.');
            }
        } catch (\Throwable $exception) {
            $this->flash('error', 'Não foi possível atualizar o status: ' . $exception->getMessage());
        }

        $redirect = url('rfb-base');
        if ($redirectQuery !== '') {
            $redirect .= '?' . $redirectQuery;
        }

        return new RedirectResponse($redirect);
    }

    public function updateContact(Request $request, array $vars): Response
    {
        $id = (int)($vars['id'] ?? 0);
        $redirectQuery = trim((string)$request->request->get('redirect_query', ''));
        $redirect = url('rfb-base') . ($redirectQuery !== '' ? '?' . $redirectQuery : '');
        $wantsJson = $this->wantsJson($request);

        if ($id < 1) {
            return $this->contactErrorResponse('Registro inválido para edição.', $redirect, $wantsJson);
        }

        $repo = new RfbProspectRepository();
        $record = $repo->find($id);
        if ($record === null) {
            return $this->contactErrorResponse('Prospecto não encontrado.', $redirect, $wantsJson);
        }

        $emailInput = trim((string)$request->request->get('email', ''));
        $phoneInput = trim((string)$request->request->get('phone', ''));

        $email = $emailInput !== '' ? strtolower($emailInput) : null;
        if ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->contactErrorResponse('E-mail informado é inválido.', $redirect, $wantsJson);
        }

        $phoneDigits = digits_only($phoneInput);
        if ($phoneDigits !== '' && strlen($phoneDigits) < 8) {
            return $this->contactErrorResponse('Telefone deve conter ao menos 8 dígitos.', $redirect, $wantsJson);
        }

        $ddd = null;
        $phone = null;
        if ($phoneDigits !== '') {
            if (strlen($phoneDigits) >= 10) {
                $ddd = substr($phoneDigits, 0, 2);
                $phone = substr($phoneDigits, 2);
            } else {
                $phone = $phoneDigits;
            }
        }

        $responsibleNameInput = trim((string)$request->request->get('responsible_name', ''));
        $responsibleName = $responsibleNameInput !== '' ? mb_substr($responsibleNameInput, 0, 120) : null;

        $birthInput = trim((string)$request->request->get('responsible_birthdate', ''));
        $responsibleBirthdate = null;
        if ($birthInput !== '') {
            $birth = \DateTimeImmutable::createFromFormat('Y-m-d', $birthInput);
            if ($birth === false) {
                return $this->contactErrorResponse('Informe a data de nascimento no formato AAAA-MM-DD.', $redirect, $wantsJson);
            }
            $responsibleBirthdate = $birth->setTime(0, 0)->getTimestamp();
        }

        $whatsappTemplates = $this->loadWhatsappTemplates();

        try {
            $updated = $repo->updateContact($id, $email, $ddd, $phone, $responsibleName, $responsibleBirthdate);
            $message = $updated ? 'Contato atualizado com sucesso.' : 'Nenhuma alteração detectada para este contato.';
            $status = $updated ? 'success' : 'info';

            $record['email'] = $email;
            $record['ddd'] = $ddd;
            $record['phone'] = $phone;
            $record['responsible_name'] = $responsibleName;
            $record['responsible_birthdate'] = $responsibleBirthdate;

            $birthLabel = $this->formatBirthdateLabel($responsibleBirthdate);
            $whatsappPayload = $this->buildWhatsappPayload($record, $whatsappTemplates);
            $phoneDigitsNormalized = digits_only(($ddd ?? '') . ($phone ?? ''));

            $payload = [
                'status' => $status,
                'message' => $message,
                'data' => [
                    'id' => $id,
                    'email' => $email ?? '',
                    'phone_display' => $this->formatPhoneDisplay($ddd, $phone),
                    'has_phone' => $phone !== null && $phone !== '',
                    'whatsapp_url' => $this->buildWhatsappUrl($ddd, $phone),
                    'phone_digits' => $phoneDigitsNormalized,
                    'responsible_name' => $responsibleName ?? '',
                    'responsible_birth_label' => $birthLabel ?? '',
                    'responsible_birth_input' => $this->formatBirthdateInput($responsibleBirthdate),
                    'whatsapp_templates' => $whatsappPayload,
                ],
            ];

            if ($wantsJson) {
                return $this->jsonResponse($payload, $updated ? 200 : 202);
            }

            $this->flash($status === 'success' ? 'success' : 'info', $message);
        } catch (\Throwable $exception) {
            if ($wantsJson) {
                return $this->jsonResponse([
                    'status' => 'error',
                    'message' => 'Falha ao atualizar contato: ' . $exception->getMessage(),
                ], 500);
            }

            $this->flash('error', 'Falha ao atualizar contato: ' . $exception->getMessage());
        }

        return new RedirectResponse($redirect);
    }

    private function contactErrorResponse(string $message, string $redirect, bool $wantsJson): Response
    {
        if ($wantsJson) {
            return $this->jsonResponse([
                'status' => 'error',
                'message' => $message,
            ], 422);
        }

        $this->flash('error', $message);
        return new RedirectResponse($redirect);
    }

    public function upload(Request $request): Response
    {
        $file = $request->files->get('rfb_spreadsheet');
        if ($file === null) {
            $this->flash('error', 'Selecione um arquivo CSV para importar.');
            return new RedirectResponse(url('rfb-base'));
        }

        if (!$file->isValid()) {
            $this->flash('error', 'Upload inválido. Tente novamente.');
            return new RedirectResponse(url('rfb-base'));
        }

        $extension = strtolower((string)$file->getClientOriginalExtension());
        if (!in_array($extension, ['csv'], true)) {
            $this->flash('error', 'Formato não suportado. Utilize arquivos CSV exportados da base RFB.');
            return new RedirectResponse(url('rfb-base'));
        }

        $targetDir = storage_path('uploads/rfb');
        if (!is_dir($targetDir)) {
            mkdir($targetDir, 0775, true);
        }

        $filename = 'rfb_' . date('Ymd_His') . '_' . uniqid('', false) . '.' . $extension;
        $filePath = $targetDir . DIRECTORY_SEPARATOR . $filename;
        $file->move($targetDir, $filename);

        $service = new BaseRfbImportService();

        try {
            $stats = $service->import($filePath, $file->getClientOriginalName());
            $imported = $stats['imported'] ?? 0;
            if ($imported === 0) {
                $this->flash('info', 'Nenhum registro novo foi adicionado. Verifique se o arquivo possui CNPJs válidos.');
            } else {
                $this->flash('success', sprintf('%d registros adicionados/atualizados na Base RFB.', $imported), $stats);
            }
        } catch (Throwable $exception) {
            $this->flash('error', 'Falha ao importar planilha: ' . $exception->getMessage());
        }

        return new RedirectResponse(url('rfb-base'));
    }

    private function flash(string $type, string $message, ?array $details = null): void
    {
        $_SESSION['rfb_upload_feedback'] = [
            'type' => $type,
            'message' => $message,
            'details' => $details,
        ];
    }

    private function rfbFiltersActive(array $filters): bool
    {
        return ($filters['query'] ?? '') !== ''
            || ($filters['state'] ?? '') !== ''
            || ($filters['city'] ?? '') !== ''
            || ($filters['cnae'] ?? '') !== ''
            || ($filters['has_email'] ?? '') === '1'
            || ($filters['has_whatsapp'] ?? '') === '1'
            || ($filters['status'] ?? 'active') === 'excluded';
    }

    private function wantsJson(Request $request): bool
    {
        if ($request->isXmlHttpRequest()) {
            return true;
        }

        $accept = strtolower((string)$request->headers->get('Accept', ''));
        return str_contains($accept, 'application/json');
    }

    private function jsonResponse(array $payload, int $status = 200): Response
    {
        return new Response(
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            $status,
            ['Content-Type' => 'application/json']
        );
    }

    private function formatPhoneDisplay(?string $ddd, ?string $phone): string
    {
        $digits = digits_only((string)$ddd . (string)$phone);
        if ($digits === '') {
            return '-';
        }

        if (strlen($digits) === 11) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 5), substr($digits, 7));
        }

        if (strlen($digits) === 10) {
            return sprintf('(%s) %s-%s', substr($digits, 0, 2), substr($digits, 2, 4), substr($digits, 6));
        }

        return $digits;
    }

    private function buildWhatsappUrl(?string $ddd, ?string $phone): ?string
    {
        $digits = digits_only((string)$ddd . (string)$phone);
        if (strlen($digits) >= 10) {
            return 'https://wa.me/55' . $digits;
        }

        return null;
    }

    private function loadWhatsappTemplates(): array
    {
        $defaults = RfbWhatsappTemplates::defaults();
        $settings = new SettingRepository();
        $stored = $settings->getMany([
            'rfb.whatsapp.partnership_template' => $defaults['partnership'],
            'rfb.whatsapp.general_template' => $defaults['general'],
        ]);

        return [
            'partnership' => (string)($stored['rfb.whatsapp.partnership_template'] ?? $defaults['partnership']),
            'general' => (string)($stored['rfb.whatsapp.general_template'] ?? $defaults['general']),
        ];
    }

    private function buildWhatsappPayload(array $record, array $templates): array
    {
        return [
            'partnership' => RfbWhatsappTemplates::render($record, $templates['partnership'] ?? ''),
            'general' => RfbWhatsappTemplates::render($record, $templates['general'] ?? ''),
        ];
    }

    private function formatBirthdateLabel(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '';
        }

        $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone(date_default_timezone_get()));
        return $date->format('d/m/Y');
    }

    private function formatBirthdateInput(?int $timestamp): string
    {
        if ($timestamp === null || $timestamp <= 0) {
            return '';
        }

        $date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone(new \DateTimeZone('UTC'));
        return $date->format('Y-m-d');
    }

}
