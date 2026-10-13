<?php

declare(strict_types=1);

// Reglas de cupo por defecto del contexto Capacity (P2-CAP-03).
//
// Estas reglas definen el total de capacidad que se asume para una unidad y franja cuando todavía no
// hay un bucket definido explícitamente. Son el punto de extensión mientras `logistics/settings` no
// las sirva versionadas: el adaptador `ConfiguredCapacityRuleProvider` las lee de aquí. El día que
// las reglas pasen a settings, se reemplaza el binding del provider sin tocar el dominio.
//
// Interpretación de la clave: 'unidad' => total por defecto para esa unidad en cualquier franja. El
// mapa 'by_slot' permite afinar por franja horaria (gana sobre el total por unidad).
return [
    // Totales por defecto por unidad de capacidad (claves = valores del enum CapacityUnit).
    'default_totals' => [
        'deliveries' => (float) env('CAPACITY_DEFAULT_DELIVERIES', 120),
        'weight' => (float) env('CAPACITY_DEFAULT_WEIGHT', 8000),
        'volume' => (float) env('CAPACITY_DEFAULT_VOLUME', 40),
    ],

    // Afinamiento opcional por franja: unidad => [ 'HH:MM-HH:MM' => total ]. Si una franja no está
    // listada, se usa el total por defecto de la unidad.
    'by_slot' => [
        // 'deliveries' => ['08:00-12:00' => 150, '16:00-20:00' => 80],
    ],

    // Calendario de despacho: los horarios configurables que definen QUÉ franjas se ofrecen y en qué
    // días. Es el punto de extensión mientras `logistics/settings` no lo sirva versionado; el calendario
    // del admin y la oferta de slots al cliente leen de aquí. Se afina por scope cuando las reglas lo
    // soporten; hoy es el default de la red.
    'dispatch_calendar' => [
        // Días hábiles de entrega (1 = lunes … 7 = domingo). El calendario no ofrece franjas fuera de ellos.
        'business_days' => [1, 2, 3, 4, 5, 6],

        // Hora de corte del pedido: un pedido tomado después de esta hora recién se entrega al día hábil
        // siguiente. En 'HH:MM' (24h).
        'order_cutoff' => env('CAPACITY_ORDER_CUTOFF', '12:00'),

        // Cuántos días hábiles hacia adelante publica el calendario (horizonte de oferta).
        'horizon_days' => (int) env('CAPACITY_CALENDAR_HORIZON_DAYS', 14),

        // Franjas de entrega del día, como ventanas 'HH:MM-HH:MM'. Son las columnas del calendario y las
        // etiquetas de los cupos (deben coincidir con el formato TimeSlot de capacity).
        'windows' => [
            '08:00-12:00',
            '12:00-16:00',
            '16:00-20:00',
        ],

        // Cupo de entregas por franja cuando no hay un bucket definido explícitamente (se refleja como
        // capacidad efectiva de origen 'rule'). null = sin default (el cupo debe definirse a mano).
        'default_slot_deliveries' => (int) env('CAPACITY_DEFAULT_SLOT_DELIVERIES', 40),
    ],
];
