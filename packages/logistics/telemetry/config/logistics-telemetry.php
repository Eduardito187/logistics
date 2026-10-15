<?php

declare(strict_types=1);

/*
| Configuración del contexto Telemetría. Umbrales de negocio (movimiento, inactividad, horario laboral,
| exceso de velocidad), límites de ingesta y host de OSRM para el map-matching de calles.
|
| Los valores por defecto son conservadores para el piloto; se afinan por .env sin tocar código.
*/

return [
    // Ingesta de pings en lote (el app agrupa lecturas para ahorrar batería/datos).
    'ingest' => [
        'max_batch' => (int) env('TELEMETRY_INGEST_MAX_BATCH', 500),
    ],

    // Umbral de movimiento: por debajo se considera detenido (ruido GPS en reposo).
    'movement' => [
        'moving_threshold_kph' => (float) env('TELEMETRY_MOVING_THRESHOLD_KPH', 3.0),
    ],

    // Trip: hueco temporal (minutos sin pings) que cierra un trip y abre otro.
    'trip' => [
        'gap_minutes' => (int) env('TELEMETRY_TRIP_GAP_MINUTES', 10),
    ],

    // Inactividad: motor encendido/detenido más de N minutos fuera de una parada → alerta (descanso/mal uso).
    'idle' => [
        'alert_minutes' => (int) env('TELEMETRY_IDLE_ALERT_MINUTES', 15),
    ],

    // Exceso de velocidad (km/h) que dispara alerta.
    'speeding' => [
        'limit_kph' => (float) env('TELEMETRY_SPEEDING_LIMIT_KPH', 90.0),
    ],

    // Horario laboral: movimiento fuera de esta franja → alerta de uso fuera de horario (posible mal uso).
    'working_hours' => [
        'start' => env('TELEMETRY_WORK_START', '06:00'),
        'end' => env('TELEMETRY_WORK_END', '20:00'),
        'timezone' => env('TELEMETRY_WORK_TZ', env('APP_TIMEZONE', 'America/La_Paz')),
    ],

    // Fan-out en vivo (ADR 016): estado caliente en Valkey + throttle de difusión por WebSockets.
    'live' => [
        // Store de cache respaldado por Valkey (habla protocolo redis). Degrada con gracia si no está.
        'cache_store' => env('TELEMETRY_LIVE_CACHE_STORE', 'redis'),
        // TTL de la última posición caliente por vehículo (segundos): un espectador recién conectado la lee.
        'ttl_seconds' => (int) env('TELEMETRY_LIVE_TTL_SECONDS', 300),
        // Coalescing: como mucho una difusión por vehículo cada N segundos (corta el tráfico de fan-out).
        'throttle_seconds' => (int) env('TELEMETRY_LIVE_THROTTLE_SECONDS', 3),
    ],

    // Analítica de calles (map-matching OSRM). Reutiliza el host OSRM de planning por defecto.
    'streets' => [
        'osrm_host' => env('OSRM_HOST', 'https://router.project-osrm.org'),
        'osrm_timeout' => (int) env('TELEMETRY_OSRM_TIMEOUT', 10),
        'min_samples' => (int) env('TELEMETRY_STREET_MIN_SAMPLES', 3),
    ],
];
