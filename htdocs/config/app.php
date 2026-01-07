<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'Marketing Suite'),
    'env' => env('APP_ENV', 'local'),
    'debug' => filter_var(env('APP_DEBUG', false), FILTER_VALIDATE_BOOL),
    'url' => env('APP_URL', 'http://localhost:8080'),
    'timezone' => env('TIMEZONE', 'America/Sao_Paulo'),
    'force_https' => filter_var(env('APP_FORCE_HTTPS', false), FILTER_VALIDATE_BOOL),
    'automation_token' => env('AUTOMATION_TOKEN', 'teste123'),
    'features' => [
        // Ativa a nova UX/pipeline do WhatsApp (modo blue/green).
        'whatsapp_next_ui' => filter_var(env('WHATSAPP_NEXT_UI', false), FILTER_VALIDATE_BOOL),
        'whatsapp_next_pipeline' => filter_var(env('WHATSAPP_NEXT_PIPELINE', false), FILTER_VALIDATE_BOOL),
    ],
];
