<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| B-3 — Cross-Origin Resource Sharing (CORS)
|--------------------------------------------------------------------------
|
| Sin este archivo, Laravel aplica el default del paquete con `allowed_origins: ['*']`
| (cualquier origen). La plataforma expone la puerta de clientes (`api/*`) y la
| autorización de canales en vivo (`broadcasting/auth`), así que acotamos los orígenes
| permitidos por env: en producción NUNCA debe quedar `*` abierto.
|
| CORS_ALLOWED_ORIGINS: lista separada por comas de orígenes exactos permitidos
|   (p. ej. "https://app.dismac.com.bo,https://admin.dismac.com.bo"). Vacío = ninguno
|   (default SEGURO: no se habilita ningún origen cruzado hasta declararlo).
| CORS_ALLOWED_ORIGINS_PATTERNS: lista separada por comas de expresiones regulares
|   para orígenes dinámicos (p. ej. previews). Vacío = ninguno.
|
| La API es por token (Bearer/device), así que `supports_credentials` queda en false
| salvo que el SPA necesite cookies de sesión cruzadas (entonces fijar la env y orígenes
| exactos, nunca `*`).
*/

return [

    'paths' => ['api/*', 'broadcasting/auth', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    // Orígenes exactos por env; default vacío (ningún origen cruzado permitido en prod sin declararlo).
    'allowed_origins' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS', ''))),
    )),

    // Patrones (regex) de orígenes, para entornos con subdominios dinámicos.
    'allowed_origins_patterns' => array_values(array_filter(
        array_map('trim', explode(',', (string) env('CORS_ALLOWED_ORIGINS_PATTERNS', ''))),
    )),

    'allowed_headers' => ['*'],

    // Exponemos el id de correlación (M-7) para que el cliente pueda reportarlo en incidencias.
    'exposed_headers' => ['X-Request-Id'],

    'max_age' => (int) env('CORS_MAX_AGE', 0),

    'supports_credentials' => (bool) env('CORS_SUPPORTS_CREDENTIALS', false),

];
