<?php

declare(strict_types=1);

return [
    'default' => env('DB_CONNECTION', 'sqlite'),
    'connections' => [
        'sqlite' => [
            'driver' => 'sqlite',
            'database' => env('DB_DATABASE', storage_path('database.sqlite')),
            'foreign_keys' => true,
            'encryption' => [
                'enabled' => filter_var(env('DB_ENCRYPTION_ENABLED', false), FILTER_VALIDATE_BOOL),
                'file' => env('DB_DATABASE_ENCRYPTED', storage_path('database.sqlite.enc')),
                'key' => env('DB_ENCRYPTION_KEY'),
                'key_file' => env('DB_ENCRYPTION_KEY_FILE', storage_path('database.key')),
            ],
        ],
        'whatsapp' => [
            'driver' => 'sqlite',
            'database' => env('WHATSAPP_DB_DATABASE', storage_path('whatsapp.sqlite')),
            'foreign_keys' => true,
            'encryption' => [
                'enabled' => filter_var(env('WHATSAPP_DB_ENCRYPTION_ENABLED', false), FILTER_VALIDATE_BOOL),
                'file' => env('WHATSAPP_DB_DATABASE_ENCRYPTED', storage_path('whatsapp.sqlite.enc')),
                'key' => env('WHATSAPP_DB_ENCRYPTION_KEY'),
                'key_file' => env('WHATSAPP_DB_ENCRYPTION_KEY_FILE', storage_path('whatsapp.key')),
            ],
        ],
        'marketing' => [
            'driver' => 'sqlite',
            'database' => env('MARKETING_DB_DATABASE', storage_path('marketing.sqlite')),
            'foreign_keys' => true,
            'encryption' => [
                'enabled' => filter_var(env('MARKETING_DB_ENCRYPTION_ENABLED', false), FILTER_VALIDATE_BOOL),
                'file' => env('MARKETING_DB_DATABASE_ENCRYPTED', storage_path('marketing.sqlite.enc')),
                'key' => env('MARKETING_DB_ENCRYPTION_KEY'),
                'key_file' => env('MARKETING_DB_ENCRYPTION_KEY_FILE', storage_path('marketing.key')),
            ],
        ],
    ],
];
