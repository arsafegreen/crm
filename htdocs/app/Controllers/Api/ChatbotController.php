<?php

declare(strict_types=1);

namespace App\Controllers\Api;

use App\Controllers\Controller;
use App\Services\CertificateService;
use App\Services\CustomerService;
use App\Services\FinanceService;
use App\Services\Whatsapp\WhatsappService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

final class ChatbotController extends Controller
{
    private WhatsappService $whatsapp;
    private CustomerService $customers;
    private CertificateService $certificates;
    private FinanceService $finance;

    public function __construct(
        ?WhatsappService $whatsapp = null,
        ?CustomerService $customers = null,
        ?CertificateService $certificates = null,
        ?FinanceService $finance = null
    ) {
        $this->whatsapp = $whatsapp ?? new WhatsappService();
        $this->customers = $customers ?? new CustomerService();
        $this->certificates = $certificates ?? new CertificateService();
        $this->finance = $finance ?? new FinanceService();
    }

    public function suggest(Request $request): JsonResponse
    {
        $payload = $this->jsonBody($request);
        $context = [
            'customer_id' => isset($payload['customer_id']) ? (int)$payload['customer_id'] : null,
            'thread_id' => isset($payload['thread_id']) ? (int)$payload['thread_id'] : null,
            'last_message' => $payload['last_message'] ?? '',
        ];

        $suggestion = $this->whatsapp->generateSuggestion($context, null);

        return new JsonResponse($suggestion);
    }

    public function actions(Request $request): JsonResponse
    {
        $payload = $this->jsonBody($request);
        $action = (string)($payload['action'] ?? '');
        $data = (array)($payload['payload'] ?? []);

        return match ($action) {
            'update_customer' => $this->handleUpdateCustomer($data),
            'issue_certificate' => $this->handleIssueCertificate($data),
            'schedule' => $this->handleSchedule($data),
            default => new JsonResponse(['error' => 'invalid_action'], 400),
        };
    }

    private function handleUpdateCustomer(array $data): JsonResponse
    {
        $id = $this->customers->upsert($data);

        return new JsonResponse(['id' => $id]);
    }

    private function handleIssueCertificate(array $data): JsonResponse
    {
        $id = $this->certificates->issue($data);

        return new JsonResponse(['id' => $id]);
    }

    private function handleSchedule(array $data): JsonResponse
    {
        // Placeholder: finance party + invoice creation as a minimal scheduling hook.
        $customerId = isset($data['customer_id']) ? (int)$data['customer_id'] : 0;
        $items = (array)($data['items'] ?? []);
        $dueDate = isset($data['due_date']) ? (int)$data['due_date'] : null;
        $meta = (array)($data['meta'] ?? []);

        $partyId = $this->finance->ensureParty($customerId);
        $invoiceId = $this->finance->createInvoice($partyId, $items, $dueDate, $meta);

        return new JsonResponse(['party_id' => $partyId, 'invoice_id' => $invoiceId]);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonBody(Request $request): array
    {
        $raw = (string)$request->getContent();
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
