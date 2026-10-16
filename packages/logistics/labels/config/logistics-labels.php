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
];
