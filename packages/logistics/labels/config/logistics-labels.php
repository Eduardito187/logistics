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
];
