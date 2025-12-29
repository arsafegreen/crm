<?php

declare(strict_types=1);

namespace App\Services;

final class CampaignAutomationConfig
{
    private string $path;

    public function __construct(?string $path = null)
    {
        $this->path = $path ?? (storage_path('marketing') . DIRECTORY_SEPARATOR . 'campaign_automation.json');
    }

    public function load(): array
    {
        $defaults = $this->defaults();

        if (!is_file($this->path)) {
            return $defaults;
        }

        $json = (string)file_get_contents($this->path);
        $data = json_decode($json, true);
        if (!is_array($data)) {
            return $defaults;
        }

        return $this->mergeAndNormalize($defaults, $data);
    }

    public function save(array $data): void
    {
        $normalized = $this->mergeAndNormalize($this->defaults(), $data);
        $this->ensureDirectory();
        file_put_contents($this->path, json_encode($normalized, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function mergeAndNormalize(array $base, array $data): array
    {
        $result = $base;

        if (isset($data['renewal']) && is_array($data['renewal'])) {
            $result['renewal'] = $this->normalizeLine($base['renewal'], $data['renewal']);
        }

        if (isset($data['birthday']) && is_array($data['birthday'])) {
            $result['birthday'] = $this->normalizeLine($base['birthday'], $data['birthday']);
        }

        return $result;
    }

    private function normalizeLine(array $base, array $line): array
    {
        $enabled = isset($line['enabled']) ? (bool)$line['enabled'] : $base['enabled'];
        $templateId = isset($line['template_id']) ? max(0, (int)$line['template_id']) : $base['template_id'];
        $senderAccountId = isset($line['sender_account_id']) ? max(0, (int)$line['sender_account_id']) : ($base['sender_account_id'] ?? null);
        $offsets = $this->parseOffsets($line['offsets'] ?? $base['offsets'] ?? []);

        if ($offsets === []) {
            $offsets = $base['offsets'];
        }

        return [
            'enabled' => $enabled,
            'template_id' => $templateId > 0 ? $templateId : null,
            'sender_account_id' => $senderAccountId > 0 ? $senderAccountId : null,
            'offsets' => $offsets,
        ];
    }

    /**
     * @param array<int|string,int|string> $offsets
     * @return int[]
     */
    private function parseOffsets(array $offsets): array
    {
        $parsed = [];
        foreach ($offsets as $offset) {
            $value = is_string($offset) ? trim($offset) : $offset;
            if ($value === '') {
                continue;
            }
            $int = (int)$value;
            $parsed[$int] = $int;
        }

        return array_values($parsed);
    }

    private function defaults(): array
    {
        return [
            'renewal' => [
                'enabled' => true,
                'template_id' => null,
                'sender_account_id' => null,
                'offsets' => [50, 30, 15, 5, 0, -5, -15, -30],
            ],
            'birthday' => [
                'enabled' => true,
                'template_id' => null,
                'sender_account_id' => null,
                'offsets' => [0],
            ],
        ];
    }

    private function ensureDirectory(): void
    {
        $dir = dirname($this->path);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }
    }
}
