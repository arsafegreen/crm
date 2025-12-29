<?php

declare(strict_types=1);

namespace App\Services\Marketing;

use App\Repositories\Marketing\AudienceListRepository;
use App\Repositories\Marketing\ContactAttributeRepository;
use App\Repositories\Marketing\MailDeliveryLogRepository;
use App\Repositories\Marketing\MarketingContactRepository;

final class ConsentService
{
    private MarketingContactRepository $contacts;
    private ContactAttributeRepository $attributes;
    private AudienceListRepository $lists;
    private MailDeliveryLogRepository $logs;

    /** @var array<string, array{label: string, description?: string, default?: bool}> */
    private array $categories;

    public function __construct(
        ?MarketingContactRepository $contacts = null,
        ?ContactAttributeRepository $attributes = null,
        ?AudienceListRepository $lists = null,
        ?MailDeliveryLogRepository $logs = null
    ) {
        $this->contacts = $contacts ?? new MarketingContactRepository();
        $this->attributes = $attributes ?? new ContactAttributeRepository();
        $this->lists = $lists ?? new AudienceListRepository();
        $this->logs = $logs ?? new MailDeliveryLogRepository();
        $this->categories = $this->loadCategories();
    }

    public function categories(): array
    {
        return $this->categories;
    }

    public function resolveContactByToken(string $token): ?array
    {
        return $this->contacts->findByPreferencesToken($token);
    }

    public function ensureToken(array $contact): string
    {
        $currentToken = isset($contact['preferences_token']) ? (string)$contact['preferences_token'] : null;
        return $this->contacts->ensurePreferencesToken((int)$contact['id'], $currentToken);
    }

    public function preferencesUrl(array $contact): string
    {
        $token = $this->ensureToken($contact);
        $base = rtrim((string)config('app.url', ''), '/');
        $path = '/preferences/' . $token;

        if ($base === '') {
            return $path;
        }

        return $base . $path;
    }

    /**
     * @return array<string, bool>
     */
    public function preferenceValues(array $contact): array
    {
        $contactId = (int)$contact['id'];
        $current = $this->attributes->list($contactId);
        $values = [];

        foreach ($this->categories as $key => $definition) {
            $raw = $current[$key] ?? null;
            if ($raw === null) {
                $values[$key] = $definition['default'] ?? true;
                continue;
            }

            $values[$key] = $this->asBool($raw);
        }

        return $values;
    }

    public function confirm(array $contact, array $context = []): void
    {
        $status = (string)($contact['consent_status'] ?? 'pending');
        if ($status === 'confirmed') {
            return;
        }

        $this->contacts->recordConsent((int)$contact['id'], 'confirmed', 'double_opt_in');
        $this->logs->record(null, 'consent_confirmed', ['source' => 'double_opt_in'], (int)$contact['id'], $context);
    }

    /**
     * @param array<string, mixed> $submitted
     */
    public function updatePreferences(array $contact, array $submitted, array $context = []): array
    {
        $contactId = (int)$contact['id'];
        $payload = [];
        $results = ['enabled' => 0, 'disabled' => 0];
        $summary = [];

        foreach ($this->categories as $key => $definition) {
            $value = array_key_exists($key, $submitted)
                ? $this->resolveInputValue($submitted[$key])
                : false;
            $payload[$key] = ['value' => $value ? '1' : '0', 'type' => 'boolean'];
            $summary[$key] = $value;
            if ($value) {
                $results['enabled']++;
            } else {
                $results['disabled']++;
            }
        }

        if ($payload !== []) {
            $this->attributes->upsertMany($contactId, $payload);
        }

        $eventType = 'consent_update';
        if ($results['enabled'] === 0 && $results['disabled'] > 0) {
            $eventType = 'consent_opt_out';
            $this->contacts->markOptOut($contactId, 'preferences_center');
            $metadata = json_encode([
                'source' => 'preferences_center',
                'occurred_at' => now(),
                'reason' => 'all_categories_disabled',
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $this->lists->unsubscribeContactEverywhere($contactId, $metadata);
        } else {
            $this->contacts->recordConsent($contactId, 'confirmed', 'preferences_center');
            $this->contacts->update($contactId, ['status' => 'active']);
        }

        $this->logs->record(null, $eventType, [
            'preferences' => $summary,
            'source' => 'preferences_center',
        ], $contactId, $context);

        return [
            'event' => $eventType,
            'summary' => $summary,
        ];
    }

    public function consentLogs(int $contactId, int $limit = 200): array
    {
        $events = ['consent_update', 'consent_opt_out', 'consent_confirmed'];
        return $this->logs->forContact($contactId, $events, $limit);
    }

    /**
     * @return array<string, array{label: string, description?: string, default?: bool}>
     */
    private function loadCategories(): array
    {
        $categories = config('marketing.consent_categories', []);
        if (!is_array($categories) || $categories === []) {
            return [];
        }

        $normalized = [];
        foreach ($categories as $key => $definition) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            if (!is_array($definition) || !isset($definition['label'])) {
                continue;
            }

            $normalized[$key] = [
                'label' => (string)$definition['label'],
            ];

            if (isset($definition['description'])) {
                $normalized[$key]['description'] = (string)$definition['description'];
            }

            $normalized[$key]['default'] = isset($definition['default'])
                ? (bool)$definition['default']
                : true;
        }

        return $normalized;
    }

    private function resolveInputValue(mixed $value): bool
    {
        if (is_string($value)) {
            $value = strtolower(trim($value));
            if (in_array($value, ['1', 'true', 'on', 'yes'], true)) {
                return true;
            }
            if (in_array($value, ['0', 'false', 'off', 'no'], true)) {
                return false;
            }
        }

        if (is_bool($value)) {
            return $value;
        }

        return (bool)$value;
    }

    private function asBool(?string $value): bool
    {
        if ($value === null) {
            return false;
        }

        $normalized = strtolower(trim($value));
        return !in_array($normalized, ['0', 'false', 'off', 'no'], true);
    }
}
