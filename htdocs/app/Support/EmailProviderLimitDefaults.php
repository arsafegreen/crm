<?php

declare(strict_types=1);

namespace App\Support;

final class EmailProviderLimitDefaults
{
    /**
     * @var array<string, array<string, int>>
     */
    private const DEFAULTS = [
        'gmail' => [
            'hourly_limit' => 30,
            'daily_limit' => 1800,
            'burst_limit' => 60,
        ],
        'outlook' => [
            'hourly_limit' => 25,
            'daily_limit' => 1000,
            'burst_limit' => 40,
        ],
        'hotmail' => [
            'hourly_limit' => 25,
            'daily_limit' => 1000,
            'burst_limit' => 40,
        ],
        'office365' => [
            'hourly_limit' => 30,
            'daily_limit' => 1500,
            'burst_limit' => 50,
        ],
        'yahoo' => [
            'hourly_limit' => 20,
            'daily_limit' => 500,
            'burst_limit' => 25,
        ],
        'zoho' => [
            'hourly_limit' => 18,
            'daily_limit' => 700,
            'burst_limit' => 30,
        ],
        'icloud' => [
            'hourly_limit' => 10,
            'daily_limit' => 200,
            'burst_limit' => 15,
        ],
        'amazonses' => [
            'hourly_limit' => 200,
            'daily_limit' => 5000,
            'burst_limit' => 400,
        ],
        'sendgrid' => [
            'hourly_limit' => 120,
            'daily_limit' => 7000,
            'burst_limit' => 500,
        ],
        'mailgrid' => [
            'hourly_limit' => 2000,
            'daily_limit' => 48000,
            'burst_limit' => 14,
        ],
        'mailtrap' => [
            'hourly_limit' => 5,
            'daily_limit' => 100,
            'burst_limit' => 10,
        ],
        'custom' => [
            'hourly_limit' => 0,
            'daily_limit' => 0,
            'burst_limit' => 0,
        ],
    ];

    /**
     * @return array<string, int>
     */
    public static function for(?string $provider): array
    {
        $key = strtolower((string)$provider);
        return self::DEFAULTS[$key] ?? self::DEFAULTS['custom'];
    }

}
