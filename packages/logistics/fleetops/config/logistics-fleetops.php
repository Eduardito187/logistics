<?php

declare(strict_types=1);

/*
| Configuración del contexto FleetOps (operación y costos de la flota propia).
| Moneda operativa, umbrales de alertas de mantenimiento y parámetros de análisis de consumo.
*/

return [
    // Moneda por defecto de todos los importes (centavos + currency en las tablas).
    'currency' => env('FLEETOPS_CURRENCY', 'BOB'),

    // Alertas de mantenimiento preventivo próximo/vencido (contra el plan por km / por días).
    'maintenance' => [
        'due_soon_km' => (int) env('FLEETOPS_MAINT_DUE_KM', 500),      // avisar a 500 km del vencimiento
        'due_soon_days' => (int) env('FLEETOPS_MAINT_DUE_DAYS', 7),     // avisar a 7 días del vencimiento
    ],

    // Análisis de consumo (km/l) y costo por km: tope defensivo para descartar lecturas absurdas.
    'consumption' => [
        'max_reasonable_kml' => (float) env('FLEETOPS_MAX_KML', 25.0),
    ],

    // Rentabilidad por corredor/entrega (ROADMAP 3-06). Hoy NO hay facturación: el ingreso es una entrada
    // OPCIONAL y configurable. No se inventan ingresos — mientras todo esto sea 0/vacío, el margen queda
    // indefinido (null) y el read-model solo muestra costo, listo para enchufar el ingreso real.
    'revenue' => [
        // Ingreso por entrega por defecto (centavos). 0 = sin ingreso (margen no disponible).
        'default_per_delivery_cents' => (int) env('FLEETOPS_REVENUE_PER_DELIVERY_CENTS', 0),
        // Mapa opcional corredor → ingreso del período (centavos). Inyectable por settings/deploy.
        'by_corridor' => [],
    ],
];
