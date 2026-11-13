<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Programación automática de la ventana de entrega (Módulo 1)
    |--------------------------------------------------------------------------
    |
    | Pesos de WindowSelector cuando el cliente solo elige la fecha y el sistema
    | asigna la ventana. Todos en 0..1; se combinan linealmente. Defaults
    | funcionales en cualquier entorno (regla "funciona en docker nuevo"); son
    | tunables desde Configuración → Promise › Auto scheduling.
    |
    */
    'auto_scheduling' => [
        'weights' => [
            // Honrar la preferencia horaria del cliente (restricción blanda alta).
            'preference' => (float) env('PROMISE_AUTOSLOT_WEIGHT_PREFERENCE', 0.6),
            // Balanceo de carga entre franjas (prefiere la franja con más cupo libre).
            'balance' => (float) env('PROMISE_AUTOSLOT_WEIGHT_BALANCE', 0.3),
            // Sesgo a franjas más tempranas del día (desempate suave).
            'early' => (float) env('PROMISE_AUTOSLOT_WEIGHT_EARLY', 0.1),
        ],

        // Horizonte (días desde hoy) que mira el barrido/cola "Unslotted" al buscar envíos con fecha y sin
        // ventana. Acota el rango de fecha prometida que se consulta a envíos; default funcional.
        'sweep_horizon_days' => (int) env('PROMISE_AUTOSLOT_SWEEP_HORIZON_DAYS', 30),
    ],
];
