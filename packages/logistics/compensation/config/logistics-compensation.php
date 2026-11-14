<?php

declare(strict_types=1);

// Configuración por defecto del contexto de compensación. Es el FALLBACK: la política real vive en la
// configuración versionada de `logistics/settings` (clave `compensation.policy`) y la pisa cuando existe.
// Estos defaults hacen que el módulo funcione en un docker nuevo sin depender del .env local
// (memoria: "funciona en docker nuevo"). Montos en centavos de BOB; porcentajes en basis points (bps).
//
// D2-7: los valores iniciales definitivos los fijan dueño + comercial + finanzas; estos son el DEFAULT
// recomendado del roadmap (tabla 2.1.4 y umbrales 2.1.5), seguros por diseño (un vale nunca supera la base).
return [
    'policy' => [
        // Topes y umbrales globales (aplican a todos los disparadores).
        'cumulative_pct' => 4000,   // 40 % del valor de la orden: tope acumulado por order_reference.
        'rounding_step' => 100,     // Bs 1: redondeo SIEMPRE hacia abajo a este múltiplo.
        'min_amount' => 1000,       // Bs 10: por debajo no se emite (queda NotCompensable).
        'auto_approve_max' => 5000, // Bs 50: hasta aquí aprueba el sistema (si el disparador lo permite).
        'level1_max' => 30000,      // Bs 300: hasta aquí aprueba un supervisor (level1); más, gerencia (level2).
        'approval_sla_hours' => 24, // Caso pendiente más de esto se escala (cron escalate-pending).
        'late_tolerance_minutes' => 60,
        'currency' => 'BOB',

        // Política por disparador. method: percentage (bps sobre la base) | fixed (centavos).
        // max_pct: tope porcentual sobre la base afectada. abs_cap: tope absoluto (centavos).
        // auto_approve: si el disparador puede auto-aprobarse (hasta auto_approve_max). validity_days: vigencia.
        'triggers' => [
            'DELIVERY_FAILED' => [
                'enabled' => true,
                'method' => 'percentage',
                'value' => 1000, // 10 %
                'max_pct' => 5000,
                'abs_cap' => 15000,
                'auto_approve' => false,
                'validity_days' => 90,
            ],
            'LATE_DELIVERY' => [
                'enabled' => true,
                'method' => 'percentage',
                'value' => 1000, // 10 %
                'max_pct' => 5000,
                'abs_cap' => 15000,
                'auto_approve' => true,
                'validity_days' => 90,
            ],
            'DAMAGED_PRODUCT' => [
                'enabled' => true,
                'method' => 'percentage',
                'value' => 1500, // 15 %
                'max_pct' => 5000,
                'abs_cap' => 50000,
                'auto_approve' => true,
                'validity_days' => 90,
            ],
            'INCOMPLETE' => [
                'enabled' => true,
                'method' => 'percentage',
                'value' => 2000, // 20 %
                'max_pct' => 10000,
                'abs_cap' => 50000,
                'auto_approve' => false,
                'validity_days' => 90,
            ],
            'LOW_RATING' => [
                'enabled' => true,
                'method' => 'percentage',
                'value' => 500, // 5 %
                'max_pct' => 2000,
                'abs_cap' => 5000,
                'auto_approve' => false,
                'validity_days' => 90,
            ],
            'MANUAL' => [
                'enabled' => true,
                'method' => 'fixed',
                'value' => 0,
                'max_pct' => 10000,
                'abs_cap' => 50000,
                'auto_approve' => false,
                'validity_days' => 90,
            ],
        ],

        // Motivos de entrega fallida que SÍ generan compensación automática (atribuibles a nosotros).
        // CUSTOMER_ABSENT / WRONG_ADDRESS / REJECTED NO compensan por defecto (D2 / tabla 2.1.4).
        'compensable_failure_reasons' => ['DAMAGED', 'OTHER'],

        // Calificación (1..5) igual o menor a este umbral se considera "puntaje bajo" (detractor).
        'low_rating_threshold' => 3,
    ],

    // Vale: vigencia por defecto y ttl del hold del POS (minutos).
    'voucher' => [
        'default_validity_days' => 90,
        'hold_ttl_minutes' => 10,
    ],
];
