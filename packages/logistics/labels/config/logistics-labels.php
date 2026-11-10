<?php

declare(strict_types=1);

// Claves de firma de etiquetas (ADR 004). Son secretos: van en .env / gestor de secretos,
// nunca en settings editables ni en el repo. Generar con: php artisan logistics:labels:keygen
return [
    // Id de la clave con la que se firman las etiquetas nuevas (campo "k" del QR).
    'active_key_id' => (int) env('LABELS_ACTIVE_KEY_ID', 1),

    // Clave privada Ed25519 activa, en base64 (64 bytes).
    'signing_key' => env('LABELS_SIGNING_KEY'),

    // Claves públicas ADICIONALES aceptadas al verificar, formato "id:base64,id:base64".
    // La pública de la clave activa se deriva sola; acá van solo las anteriores aún vigentes.
    'previous_public_keys' => env('LABELS_PREVIOUS_PUBLIC_KEYS', ''),

    // Simbología que lleva la etiqueta impresa (ZPL). A diferencia de las claves de arriba, NO es un
    // secreto: es una regla operativa. Se gobierna versionada en `logistics/settings` bajo la clave
    // `labels.symbology`; este valor es solo el DEFAULT cuando no hay versión publicada para la fecha.
    // Valores: 'qr' (solo QR firmado), 'barcode' (solo Code128 con el id del bulto) o 'both' (ambos).
    'symbology' => env('LABELS_SYMBOLOGY', 'both'),

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
