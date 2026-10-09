<?php

return [
    // Regras de calendário no fuso do negócio; instantes armazenados em UTC.
    'business_timezone' => env('PLANOS_BUSINESS_TIMEZONE', 'America/Sao_Paulo'),

    // Ambiente de homologação: exibido em todas as telas e nos links gerados.
    'is_homologation' => env('PLANOS_HOMOLOGATION', true),

    'proposals' => [
        'expiry_days' => (int) env('PLANOS_PROPOSAL_EXPIRY_DAYS', 7),
        'link_expiry_days' => (int) env('PLANOS_LINK_EXPIRY_DAYS', 7),
    ],

    'otp' => [
        // Proposta técnica: confirmar o contato por código de uso único antes do aceite (seção 5).
        'required' => (bool) env('PLANOS_OTP_REQUIRED', true),
        'ttl_minutes' => 10,
        'max_attempts' => 5,
        'max_per_hour' => 5,
    ],

    'benefits' => [
        'reservation_ttl_hours' => (int) env('PLANOS_RESERVATION_TTL_HOURS', 48),
    ],

    'financial' => [
        // Limite de desatualização da informação financeira antes de recusar autorização (seção 27.5).
        'max_staleness_hours' => (int) env('PLANOS_FINANCIAL_MAX_STALENESS_HOURS', 26),
    ],

    'billing' => [
        // fake: simulador de homologação; vindi: API de recorrência da Vindi.
        'driver' => env('PLANOS_BILLING_DRIVER', 'fake'),
        'vindi' => [
            'base_url' => env('VINDI_BASE_URL', 'https://sandbox-app.vindi.com.br/api/v1'),
            'api_key' => env('VINDI_API_KEY'),
            'plan_id' => env('VINDI_PLAN_ID'),
            'product_id' => env('VINDI_PRODUCT_ID'),
            'enrollment_product_id' => env('VINDI_ENROLLMENT_PRODUCT_ID'),
            'payment_method_code' => env('VINDI_PAYMENT_METHOD', 'credit_card'),
            'webhook_secret' => env('VINDI_WEBHOOK_SECRET'),
            'timeout' => 20,
        ],
    ],

    'integrations' => [
        // Sem API confirmada (seção 12.2 e 12.3): tarefas manuais auditadas.
        'simplesvet_mode' => env('PLANOS_SIMPLESVET_MODE', 'manual'),
        'tudoprapet_mode' => env('PLANOS_TUDOPRAPET_MODE', 'manual'),
    ],

    'uploads' => [
        'max_kb' => 15360,
        'contract_mimes' => ['application/pdf', 'image/jpeg', 'image/png'],
    ],

    'documents_disk' => env('PLANOS_DOCUMENTS_DISK', 'documents'),
];
