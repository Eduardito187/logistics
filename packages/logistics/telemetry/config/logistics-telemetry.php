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

    // Exceso de velocidad (km/h) que dispara alerta. La alerta se levanta recién cuando el exceso se
    // SOSTIENE `sustained_seconds` (un pico suelto de GPS no alerta). 0 = alerta instantánea (histórico).
    'speeding' => [
        'limit_kph' => (float) env('TELEMETRY_SPEEDING_LIMIT_KPH', 90.0),
        'sustained_seconds' => (int) env('TELEMETRY_SPEEDING_SUSTAINED_SECONDS', 20),
    ],

    // Desvío de ruta: el vehículo se aleja de la geometría de su ruta planificada (plan publicado del día)
    // más de `max_distance_m` durante al menos `min_seconds` → alerta route_deviation. Sin geometría
    // planificada (plan sin trazar, o vehículo sin plan) no se evalúa.
    'deviation' => [
        'enabled' => (bool) env('TELEMETRY_DEVIATION_ENABLED', true),
        'max_distance_m' => (float) env('TELEMETRY_DEVIATION_MAX_DISTANCE_M', 300.0),
        'min_seconds' => (int) env('TELEMETRY_DEVIATION_MIN_SECONDS', 120),
    ],

    // Migas de pan por envío: traza del vehículo mientras el envío estuvo a bordo, reducida.
    'breadcrumbs' => [
        // Distancia mínima entre dos puntos consecutivos de la traza reducida (metros).
        'min_spacing_m' => (float) env('TELEMETRY_BREADCRUMBS_MIN_SPACING_M', 25.0),
        // Tope de puntos devueltos (se reduce por paso uniforme si se excede).
        'max_points' => (int) env('TELEMETRY_BREADCRUMBS_MAX_POINTS', 500),
        // Ventana máxima hacia atrás desde el fin (horas): acota un envío que quedó "a bordo" sin cerrar.
        'max_window_hours' => (int) env('TELEMETRY_BREADCRUMBS_MAX_WINDOW_HOURS', 72),
    ],

    // ETA dinámica por parada restante, recalculada desde la última posición. NO hay fuente de tráfico en
    // vivo: con OSRM configurado se usan las duraciones viales de OSRM (sin tráfico); sin OSRM, distancia en
    // línea recta × factor de desvío / velocidad media. El cálculo con OSRM corre SOLO en el comando
    // programado (nunca dentro de un request); la lectura usa lo guardado o el respaldo en línea recta.
    'eta' => [
        // Host OSRM para las duraciones viales. Vacío = sin OSRM (respaldo en línea recta; sirve en docker nuevo).
        'osrm_host' => (string) env('TELEMETRY_ETA_OSRM_HOST', ''),
        'osrm_timeout' => (int) env('TELEMETRY_ETA_OSRM_TIMEOUT', 5),
        // Velocidad media urbana para el respaldo en línea recta (km/h).
        'average_speed_kph' => (float) env('TELEMETRY_ETA_AVERAGE_SPEED_KPH', 25.0),
        // Factor de desvío de la línea recta a la distancia vial real (1.3 ≈ trazado urbano típico).
        'detour_factor' => (float) env('TELEMETRY_ETA_DETOUR_FACTOR', 1.3),
        // Tiempo de servicio estimado por parada (minutos) que se suma antes de salir a la siguiente.
        'service_minutes' => (int) env('TELEMETRY_ETA_SERVICE_MINUTES', 5),
        // Una posición más vieja que esto no recalcula ETA (el vehículo dejó de reportar).
        'max_position_age_minutes' => (int) env('TELEMETRY_ETA_MAX_POSITION_AGE_MINUTES', 30),
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

    // Poda de retención de los pings GPS crudos (comando logistics:telemetry:prune-pings). El RAW viejo
    // es desechable: trips, posición en vivo y analítica de calles persisten aparte. Retención corta.
    'prune' => [
        // Días de retención del RAW de pings (se miden desde recorded_at).
        'pings_retention_days' => (int) env('TELEMETRY_PINGS_RETENTION_DAYS', 60),

        // Filas por lote de borrado (acota el bloqueo).
        'chunk' => (int) env('DB_PRUNE_CHUNK', 5000),

        // Pausa entre lotes en milisegundos (alivia la carga del motor en producción).
        'pause_ms' => (int) env('DB_PRUNE_PAUSE_MS', 100),
    ],
];
