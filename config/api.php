<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Respuestas de error RFC 7807 (application/problem+json)
    |--------------------------------------------------------------------------
    |
    | `type` de cada problema es una URI de referencia. Se construye como
    | "<base>/<código>" a partir del catálogo de códigos (App\Http\Problem\ErrorCode).
    | La base es relativa por defecto para no acoplar el contrato a un host; puede
    | apuntarse a la documentación pública con API_PROBLEM_TYPE_BASE_URI.
    |
    */

    'problem_type_base_uri' => env('API_PROBLEM_TYPE_BASE_URI', '/problems'),

    /*
    |--------------------------------------------------------------------------
    | Idempotencia de requests de escritura (app offline)
    |--------------------------------------------------------------------------
    |
    | El middleware `idempotency` deduplica reintentos de la app offline usando la
    | cabecera `Idempotency-Key`. La respuesta de la primera ejecución se guarda en
    | Valkey (store de caché por defecto; en tests es el array store) y se reproduce
    | ante un reintento con la MISMA clave y el MISMO cuerpo.
    |
    */

    'idempotency' => [

        // Cabecera que transporta la clave de idempotencia.
        'header' => env('API_IDEMPOTENCY_HEADER', 'Idempotency-Key'),

        // Store de caché donde persisten las respuestas. null = store por defecto (Valkey).
        'store' => env('API_IDEMPOTENCY_STORE'),

        // Vida de la respuesta guardada, en segundos (24 h por defecto).
        'ttl' => (int) env('API_IDEMPOTENCY_TTL', 86400),

        // Métodos de escritura a los que se aplica la deduplicación.
        'methods' => ['POST', 'PUT', 'PATCH', 'DELETE'],

        // Longitud máxima aceptada para la clave (evita claves abusivas).
        'max_key_length' => (int) env('API_IDEMPOTENCY_MAX_KEY_LENGTH', 255),

        // Segundos que un request en vuelo bloquea a otro con la misma clave.
        'lock_seconds' => (int) env('API_IDEMPOTENCY_LOCK_SECONDS', 15),

    ],

];
