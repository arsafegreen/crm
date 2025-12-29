<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Repositories\Marketing\MarketingContactRepository;

/**
 * Lightweight pre-send guard to suppress risky deliveries and classify failures.
 */
final class DeliveryGuard
{
    private MarketingContactRepository $contacts;

    /** @var array<string, array{ok: bool, checked_at: int}> */
    private array $mxCache = [];
    private int $mxCacheTtl = 14400; // 4h cache to handle high-volume checks safely.

    public function __construct(?MarketingContactRepository $contacts = null)
    {
        $this->contacts = $contacts ?? new MarketingContactRepository();
    }

    /**
     * @return array{deliverable: bool, reason: ?string, contact_id: ?int, classification: string}
     */
    public function precheck(string $recipientEmail): array
    {
        $normalized = $this->normalizeEmail($recipientEmail);
        if ($normalized === null) {
            return [
                'deliverable' => false,
                'reason' => 'E-mail inválido.',
                'contact_id' => null,
                'classification' => 'hard_bounce',
            ];
        }

        $contact = $this->contacts->findByEmail($normalized);
        if ($contact === null) {
            return [
                'deliverable' => true,
                'reason' => null,
                'contact_id' => null,
                'classification' => 'none',
            ];
        }

        $status = (string)($contact['status'] ?? 'active');
        if ($status !== 'active') {
            return [
                'deliverable' => false,
                'reason' => 'Contato inativo/suprimido.',
                'contact_id' => (int)$contact['id'],
                'classification' => 'hard_bounce',
            ];
        }

        if ($this->hasMxIssue($normalized)) {
            return [
                'deliverable' => false,
                'reason' => 'Dominio sem MX valido.',
                'contact_id' => $contact ? (int)$contact['id'] : null,
                'classification' => 'hard_bounce',
            ];
        }

        $bounceCount = (int)($contact['bounce_count'] ?? 0);
        $complaints = (int)($contact['complaint_count'] ?? 0);
        $consentStatus = (string)($contact['consent_status'] ?? '');

        if ($complaints > 0) {
            return [
                'deliverable' => false,
                'reason' => 'Contato marcou como spam.',
                'contact_id' => (int)$contact['id'],
                'classification' => 'hard_bounce',
            ];
        }

        if (in_array($consentStatus, ['opted_out', 'blocked'], true)) {
            return [
                'deliverable' => false,
                'reason' => 'Contato opt-out/bloqueado.',
                'contact_id' => (int)$contact['id'],
                'classification' => 'hard_bounce',
            ];
        }

        if ($bounceCount >= 3) {
            return [
                'deliverable' => false,
                'reason' => 'Contato com histórico de bounces.',
                'contact_id' => (int)$contact['id'],
                'classification' => 'hard_bounce',
            ];
        }

        return [
            'deliverable' => true,
            'reason' => null,
            'contact_id' => (int)$contact['id'],
            'classification' => 'none',
        ];
    }

    public function classifyError(string $message): string
    {
        $normalized = strtolower($message);

        if ($this->containsAny($normalized, ['550', '551', '552', '553', '554', 'user unknown', 'mailbox unavailable', 'no such user', 'blocked', 'blacklist', 'policy rejection'])) {
            return 'hard_bounce';
        }

        if ($this->containsAny($normalized, ['421', '450', '451', '452', 'temporarily', 'temporary', 'rate limit', 'greylist', 'mailbox full'])) {
            return 'soft_bounce';
        }

        return 'hard_bounce';
    }

    private function normalizeEmail(string $email): ?string
    {
        $value = strtolower(trim($email));
        if ($value === '' || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return $value;
    }

    private function hasMxIssue(string $email): bool
    {
        $domain = substr($email, strpos($email, '@') + 1);
        if (!$domain) {
            return true;
        }

        $cacheKey = strtolower($domain);
        $now = time();
        if (isset($this->mxCache[$cacheKey])) {
            $cached = $this->mxCache[$cacheKey];
            if (($now - $cached['checked_at']) <= $this->mxCacheTtl) {
                return $cached['ok'] === false;
            }
        }

        $ok = true;
        try {
            $ok = function_exists('checkdnsrr') ? checkdnsrr($domain, 'MX') : true;
        } catch (\Throwable) {
            $ok = true; // Do not block if DNS check fails unexpectedly.
        }

        $this->mxCache[$cacheKey] = [
            'ok' => $ok,
            'checked_at' => $now,
        ];

        return $ok === false;
    }

    /**
     * @param string[] $needles
     */
    private function containsAny(string $haystack, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (str_contains($haystack, $needle)) {
                return true;
            }
        }

        return false;
    }
}
