<?php

declare(strict_types=1);

namespace App\Services\Security;

use Symfony\Component\HttpFoundation\Request;

final class GeoIpService
{
    private const TIMEOUT = 3.0;

    public function lookupFromRequest(Request $request): array
    {
        $ip = $this->extractIp($request);
        return $this->lookup($ip);
    }

    public function lookup(?string $ip): array
    {
        $ip = $ip !== null ? trim($ip) : '';

        if ($ip === '' || !$this->isPublicIp($ip)) {
            return [
                'ip' => $ip !== '' ? $ip : null,
                'label' => $ip === '' ? 'Origem desconhecida' : 'Rede interna / VPN',
                'city' => null,
                'region' => null,
                'country' => null,
            ];
        }

        $endpoint = sprintf('http://ip-api.com/json/%s?fields=status,country,regionName,city,message&lang=pt-BR', rawurlencode($ip));

        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => self::TIMEOUT,
                'header' => "User-Agent: MarketingSuite/1.0\r\n",
            ],
        ]);

        $city = null;
        $region = null;
        $country = null;

        try {
            $response = @file_get_contents($endpoint, false, $context);
            if (is_string($response) && $response !== '') {
                $decoded = json_decode($response, true, flags: JSON_THROW_ON_ERROR);
                if (is_array($decoded) && ($decoded['status'] ?? '') === 'success') {
                    $city = $this->normalizePart($decoded['city'] ?? null);
                    $region = $this->normalizePart($decoded['regionName'] ?? null);
                    $country = $this->normalizePart($decoded['country'] ?? null);
                }
            }
        } catch (\Throwable) {
            // Ignore lookup failures and fallback to generic label below.
        }

        $labelParts = array_filter([$city, $region, $country], static fn (?string $part): bool => $part !== null && $part !== '');
        $label = $labelParts !== [] ? implode(', ', $labelParts) : 'Localização não disponível';

        return [
            'ip' => $ip,
            'label' => $label,
            'city' => $city,
            'region' => $region,
            'country' => $country,
        ];
    }

    private function extractIp(Request $request): ?string
    {
        $forwarded = (string)$request->headers->get('X-Forwarded-For', '');
        if ($forwarded !== '') {
            $parts = explode(',', $forwarded);
            if ($parts !== []) {
                $candidate = trim($parts[0]);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        $clientIp = $request->getClientIp();
        if (is_string($clientIp) && $clientIp !== '') {
            return $clientIp;
        }

        $serverAddr = $request->server->get('REMOTE_ADDR');
        return is_string($serverAddr) && $serverAddr !== '' ? $serverAddr : null;
    }

    private function isPublicIp(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        return (bool)filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        );
    }

    private function normalizePart(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }
}
