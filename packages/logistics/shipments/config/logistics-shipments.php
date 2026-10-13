<?php

declare(strict_types=1);

// Configuración del contexto Recepción de envíos. Los secretos (clave de Google) van en .env /
// gestor de secretos, nunca en settings editables ni en el repo.
return [
    // Geocodificador: adaptador real de puntaje de confianza de direcciones (AddressConfidenceScorer).
    'geocoder' => [
        // Clave de la Google Geocoding API. Sin clave, el contexto cae al scorer neutro (stub).
        'google_key' => env('SHIPMENTS_GEOCODER_GOOGLE_KEY'),

        // Endpoint de la Geocoding API (se deja configurable para pruebas y proxies corporativos).
        'endpoint' => env(
            'SHIPMENTS_GEOCODER_ENDPOINT',
            'https://maps.googleapis.com/maps/api/geocode/json',
        ),

        // Sesgo geográfico: Bolivia y español, para desambiguar direcciones locales.
        'region' => env('SHIPMENTS_GEOCODER_REGION', 'bo'),
        'language' => env('SHIPMENTS_GEOCODER_LANGUAGE', 'es'),

        // Timeout por request en segundos. Geocodificar nunca debe colgar un worker.
        'timeout' => (int) env('SHIPMENTS_GEOCODER_TIMEOUT', 5),

        // Caché de resultados: store de Valkey/Redis y TTL en segundos (por defecto 30 días).
        // Las direcciones casi no cambian; cachear evita gastar cuota y acelera reprocesos masivos.
        'cache_store' => env('SHIPMENTS_GEOCODER_CACHE_STORE', 'redis'),
        'cache_ttl' => (int) env('SHIPMENTS_GEOCODER_CACHE_TTL', 2592000),

        // Cuota diaria de requests reales (no cacheados). Protege el presupuesto de la API:
        // al superarla se corta con una excepción en vez de seguir facturando. 0 = sin límite.
        'daily_quota' => (int) env('SHIPMENTS_GEOCODER_DAILY_QUOTA', 40000),
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
