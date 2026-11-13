<?php

declare(strict_types=1);

// Claves de firma de etiquetas (ADR 004). Son secretos: van en .env / gestor de secretos,
// nunca en settings editables ni en el repo. Generar con: php artisan logistics:labels:keygen
return [
    // Id de la clave con la que se firman las etiquetas nuevas (campo "k" del QR).
    'active_key_id' => (int) env('LABELS_ACTIVE_KEY_ID', 1),

    // Clave privada Ed25519 activa, en base64 (64 bytes).
    'signing_key' => env('LABELS_SIGNING_KEY'),

    // Modo ABIERTO (sin clave): la etiqueta es un JSON abierto y su autenticidad la da el REGISTRO (el
    // label_id es un ULID emitido por el sistema y rastreado toda su vida), no una firma secreta. Así una
    // etiqueta impresa NUNCA se descarta por un problema de clave. Es el DEFAULT (no hay que configurar nada).
    // Para usar firma criptográfica Ed25519 en su lugar: LABELS_OPEN=false + LABELS_SIGNING_KEY.
    'open' => (bool) env('LABELS_OPEN', true),

    // Claves públicas ADICIONALES aceptadas al verificar, formato "id:base64,id:base64".
    // La pública de la clave activa se deriva sola; acá van solo las anteriores aún vigentes.
    'previous_public_keys' => env('LABELS_PREVIOUS_PUBLIC_KEYS', ''),

    // Simbología que lleva la etiqueta impresa (ZPL). A diferencia de las claves de arriba, NO es un
    // secreto: es una regla operativa. Se gobierna versionada en `logistics/settings` bajo la clave
    // `labels.symbology`; este valor es solo el DEFAULT cuando no hay versión publicada para la fecha.
    // Valores: 'qr' (solo QR firmado), 'barcode' (solo Code128 con el id del bulto) o 'both' (ambos).
    'symbology' => env('LABELS_SYMBOLOGY', 'both'),

    // Tamaño/perfil de la etiqueta (roadmap 03 — Etiquetas Lite/chicas). Como la simbología, NO es un
    // secreto: es una regla operativa versionada en `logistics/settings` bajo la clave `labels.size`. Este
    // bloque es solo el DEFAULT cuando no hay versión publicada. El tamaño es un atributo de PRESENTACIÓN:
    // la misma etiqueta se reimprime en cualquier perfil sin cambiar su id ni el QR firmado.
    'size' => [
        // Perfil por defecto: 'standard' (100x75), 'lite' (50x25), 'lite_40x30' o 'micro'. Decisión del
        // dueño (§7): el DEFAULT global es 'standard' (nada cambia si no hay regla ni medida chica).
        'default_profile' => env('LABELS_DEFAULT_SIZE_PROFILE', 'standard'),

        // Perfil que se usa para los productos «chicos» (por regla o por medidas). DEFAULT recomendado: lite.
        'small_profile' => env('LABELS_SMALL_SIZE_PROFILE', 'lite'),

        // Umbrales de medidas con los que un producto se considera «chico» y entra en el perfil chico.
        // Un producto es chico si su lado más largo (cm) <= max_longest_side_cm y, si se conoce, su peso
        // (kg) <= max_weight_kg. null desactiva ese criterio. Sin medidas, nunca se adivina: va a 'standard'.
        'small_max_longest_side_cm' => 25.0,
        'small_max_weight_kg' => 2.0,

        // Reglas por SKU (la primera que matchea gana): [{match: 'sku_exact'|'sku_prefix', value, profile}].
        // Vacío por defecto; la operación las carga en Configuración › Labels › Label sizes.
        'rules' => [],
    ],

    // Origen (campo `org` del QR) de la EMISIÓN POR ORDEN (body vacío): el backend lo deriva por envío con
    // OriginCdSelector. El VALOR del `org` NO es contrato con la app (solo el formato canónico firmado lo
    // es), así que esta regla lo fija de forma estable. Ver Domain\Service\OriginCdSelector.
    'origin' => [
        // CD por defecto cuando no se resuelve por departamento del destino, coordenadas ni prefijo.
        'default_node_code' => env('LABELS_DEFAULT_ORIGIN_NODE_CODE', 'CD-SCZ-01'),

        // Prefijo de la referencia del pedido → departamento (fallback sin coordenadas). El prefijo más
        // largo gana (CBBA antes que CBB). Coincide con la convención del seeder.
        'reference_prefixes' => [
            'SCZ' => 'santa_cruz',
            'LPZ' => 'la_paz',
            'LP' => 'la_paz',
            'CBBA' => 'cochabamba',
            'CBB' => 'cochabamba',
        ],
    ],
];
