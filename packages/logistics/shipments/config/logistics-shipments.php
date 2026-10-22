<?php

declare(strict_types=1);

// Configuración del contexto Recepción de envíos. Todo el geocoding es open-source y self-host
// (Nominatim/OpenStreetMap): sin Google, sin servicios de pago ni claves de API.
return [
    // Geocodificador: resuelve una dirección a coordenada + confianza (contrato Geocoder).
    'geocoder' => [
        // Proveedor a usar: 'nominatim' (OSM, por defecto) o 'null' (stub neutro). Vacío = AUTO:
        // Nominatim si está habilitado, y si se deshabilita (entornos sin salida), el stub neutro.
        'driver' => env('SHIPMENTS_GEOCODER_DRIVER', 'nominatim'),

        // Caché de resultados: store de Valkey/Redis y TTL en segundos (por defecto 30 días).
        // Las direcciones casi no cambian; cachear evita gastar cuota/llamadas y acelera reprocesos.
        'cache_store' => env('SHIPMENTS_GEOCODER_CACHE_STORE', 'redis'),
        'cache_ttl' => (int) env('SHIPMENTS_GEOCODER_CACHE_TTL', 2592000),

        // Dedup PERSISTENTE (tabla geocoding_cache): deduplica la MISMA dirección entre reprocesos,
        // reinicios y purgas de Valkey, y mide la repetición (hits). Es la fuente dedup durable; el
        // store de Valkey de arriba la acelera por delante (en el geocoder real). Se puede apagar.
        'persistent_cache' => [
            'enabled' => (bool) env('SHIPMENTS_GEOCODER_PERSISTENT_CACHE', true),
        ],

        // Confianza PROVISIONAL del ingreso (sin I/O): se asigna al recibir el pedido, antes de la
        // geocodificación real (asíncrona). Más alta si la fuente ya trajo coordenada; baja si no.
        'provisional' => [
            'with_coordinates' => (int) env('SHIPMENTS_GEOCODER_PROVISIONAL_WITH_COORDS', 70),
            'without_coordinates' => (int) env('SHIPMENTS_GEOCODER_PROVISIONAL_WITHOUT_COORDS', 30),
        ],

        // Criterios de puntaje de confianza (configurables): score base 0..100 por tipo de coincidencia
        // (match exacto vs interpolado vs aproximado), penalización por coincidencia parcial y score
        // cuando el proveedor no ubica la dirección. Los adaptadores los comparten para puntuar igual.
        'scoring' => [
            'match_scores' => [
                'exact' => (int) env('SHIPMENTS_GEOCODER_SCORE_EXACT', 95),
                'interpolated' => (int) env('SHIPMENTS_GEOCODER_SCORE_INTERPOLATED', 80),
                'approximate' => (int) env('SHIPMENTS_GEOCODER_SCORE_APPROXIMATE', 45),
                'none' => (int) env('SHIPMENTS_GEOCODER_SCORE_NONE', 10),
            ],
            'partial_match_penalty' => (int) env('SHIPMENTS_GEOCODER_PARTIAL_PENALTY', 25),
            'no_result_score' => (int) env('SHIPMENTS_GEOCODER_NO_RESULT_SCORE', 10),
        ],

        // Nominatim (OpenStreetMap): geocoder open-source self-host, sin clave ni cuota de pago.
        'nominatim' => [
            // Habilitado por defecto. Se puede apagar (entornos sin salida ni servicio) para caer
            // directo al stub neutro (null).
            'enabled' => (bool) env('SHIPMENTS_GEOCODER_NOMINATIM_ENABLED', true),

            // Endpoint del servicio /search. Por defecto apunta a la instancia Nominatim self-host del
            // docker-compose (NOMINATIM_URL=http://nominatim:8080); SHIPMENTS_GEOCODER_NOMINATIM_ENDPOINT
            // permite fijar la URL /search completa (p. ej. detrás de un proxy o en otro host).
            'endpoint' => env(
                'SHIPMENTS_GEOCODER_NOMINATIM_ENDPOINT',
                rtrim((string) env('NOMINATIM_URL', 'http://nominatim:8080'), '/').'/search',
            ),

            // User-Agent identificable: OBLIGATORIO por la política de OSM (sin él, bloquean).
            'user_agent' => env('SHIPMENTS_GEOCODER_NOMINATIM_USER_AGENT', 'DismacLogistics/1.0 (logistica@dismac.com.bo)'),

            // Sesgo geográfico e idioma.
            'country_codes' => env('SHIPMENTS_GEOCODER_NOMINATIM_COUNTRY', 'bo'),
            'language' => env('SHIPMENTS_GEOCODER_NOMINATIM_LANGUAGE', 'es'),

            // Timeout por request en segundos.
            'timeout' => (int) env('SHIPMENTS_GEOCODER_NOMINATIM_TIMEOUT', 5),

            // Intervalo mínimo entre requests reales en milisegundos (política OSM: máx ~1 req/s).
            'min_interval_ms' => (int) env('SHIPMENTS_GEOCODER_NOMINATIM_MIN_INTERVAL_MS', 1000),
        ],
    ],

    // Fuente Magento (SHP-07): lectura de pedidos pendientes para ingresarlos por la API única.
    // Sin base_url configurada, el contexto cae al lector stub (no trae pedidos), para arrancar y
    // desarrollar sin credenciales. Si Magento no entra al piloto, se deja vacío.
    'magento' => [
        // URL base de la tienda Magento (sin la ruta /rest). Vacío = lector stub.
        'base_url' => env('SHIPMENTS_MAGENTO_BASE_URL'),

        // Token de servicio (Bearer) con permiso de lectura de pedidos. Va en .env / gestor de secretos.
        'access_token' => env('SHIPMENTS_MAGENTO_ACCESS_TOKEN'),

        // Estado de los pedidos a traer (los que están listos para despacho).
        'status' => env('SHIPMENTS_MAGENTO_STATUS', 'processing'),

        // Tamaño de página de la consulta a Magento.
        'page_size' => (int) env('SHIPMENTS_MAGENTO_PAGE_SIZE', 100),

        // Timeout por request en segundos. Traer pedidos nunca debe colgar un worker.
        'timeout' => (int) env('SHIPMENTS_MAGENTO_TIMEOUT', 10),
    ],
];
