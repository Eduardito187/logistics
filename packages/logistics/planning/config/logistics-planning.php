<?php

declare(strict_types=1);

// Configuración del contexto Planificación. Valores por env; sin env, defaults seguros para desarrollo.
return [
    // Host del ruteador OSRM (Open Source Routing Machine, el motor de rutas de OpenStreetMap) que usa
    // el optimizador de rutas para el orden óptimo de paradas y las ETA, y el mapa de Ejecución para la
    // geometría de la ruta por calles. Por defecto, el servidor público de demo (solo para desarrollo;
    // en producción debe apuntar a una instancia propia). Sin barra final.
    'osrm_host' => env('OSRM_HOST', 'https://router.project-osrm.org'),

    // Tope de espera (segundos) de cada llamada a OSRM desde el backend. Pasado el tope, el optimizador
    // degrada al orden de entrada en vez de bloquear la generación del plan.
    'osrm_timeout' => (int) env('OSRM_TIMEOUT', 5),

    // Motor de ruteo ENCHUFABLE del auto-planificador. Detrás del contrato RoutingBackend se elige el
    // backend por configuración; cambiarlo no toca el dominio ni el runner.
    'routing' => [
        // Backend por defecto:
        //  - 'builtin': Clarke-Wright (savings) para asignar paradas a camiones + 2-opt/Or-opt para el
        //    orden, todo sobre la matriz real por carretera. Es el recomendado y el DEFAULT.
        //  - 'osrm_trip': comportamiento anterior (empaque greedy + orden por OSRM /trip por camión).
        //  - 'vroom': delega el VRP a un servidor VROOM; si VROOM_URL no está configurado, cae a 'builtin'.
        'backend' => env('PLANNING_ROUTING_BACKEND', 'builtin'),

        // URL del servidor VROOM (opcional). Solo se usa con backend 'vroom'; sin ella, 'vroom' cae a
        // 'builtin'. Debe apuntar al endpoint que acepta el problema por POST (p. ej. http://vroom:3000).
        'vroom_url' => env('VROOM_URL'),

        // Parámetros de la MATRIZ real (OSRM /table):
        'matrix' => [
            // Tope de coordenadas por llamada a /table. Conjuntos mayores se resuelven por bloques
            // (submatrices) y se ensamblan, para escalar a cientos de paradas. El demo público tolera ~100.
            'max_table_coordinates' => (int) env('OSRM_TABLE_MAX_COORDINATES', 100),

            // TTL (segundos) de la caché de matrices en Valkey. Un mismo conjunto de coordenadas no cambia;
            // reusos del día (reintentos, replanificación) no vuelven a pegarle al ruteador. 0 desactiva.
            'cache_ttl' => (int) env('PLANNING_MATRIX_CACHE_TTL', 86400),
        ],

        // Velocidad media (km/h) para estimar tiempos cuando se degrada a haversine (OSRM caído).
        'fallback_speed_kmh' => (float) env('PLANNING_FALLBACK_SPEED_KMH', 30.0),
    ],

    // Auto-planificador diario (comando GenerateDailyPlan). Parámetros CONFIGURABLES por defecto; el
    // comando/API puede sobreescribir cualquiera por corrida. Es el stub de settings: cuando
    // logistics/settings sirva estas reglas versionadas, se cambia solo el binding del provider.
    'auto_planner' => [
        // Hora de salida del depósito (HH:MM). Con ella se calculan las ETA desde el día del plan.
        'default_depart_time' => env('PLANNING_DEPART_TIME', '07:30'),

        // Holgura de capacidad (0..1): fracción del kg/m3 nominal que se reserva (0.1 = usar el 90 %).
        'capacity_slack' => (float) env('PLANNING_CAPACITY_SLACK', 0.1),

        // Peso y volumen asumidos por bulto cuando el envío no trae datos reales (para el bin-packing).
        'default_weight_kg_per_package' => (float) env('PLANNING_DEFAULT_WEIGHT_KG', 10.0),
        'default_volume_m3_per_package' => (float) env('PLANNING_DEFAULT_VOLUME_M3', 0.05),

        // Nodo CD de origen de las rutas (opcional). Informativo para el plan.
        'origin_node_id' => env('PLANNING_ORIGIN_NODE_ID'),
    ],
];
