<?php

declare(strict_types=1);

// Configuración del contexto Ejecución. Valores por env; sin env, defaults seguros para desarrollo.
return [
    // Disco S3-compatible donde se guardan las fotos de la prueba de entrega. Debe existir en
    // config/filesystems.php; por defecto, el disco `pod` que define la plataforma (privado, S3).
    'pod_disk' => env('EXECUTION_POD_DISK', 'pod'),

    // Discos de evidencia operativa por tipo (ver config/filesystems.php). La subida de fotos elige el
    // disco según el tipo: `pod` (prueba de entrega, privado) o `damage` (daños/reclamos, privado). Así
    // la evidencia legal de la entrega y la de siniestros viven separadas, cada una con su ciclo de vida.
    'evidence_disks' => [
        'pod' => env('EXECUTION_POD_DISK', 'pod'),
        'damage' => env('EXECUTION_DAMAGE_DISK', 'damage'),
    ],

    // Reintentos al subir una evidencia al almacenamiento S3-compatible: la red del andén es inestable,
    // así que un fallo transitorio se reintenta antes de rendirse. La app igual reencola offline.
    'evidence_upload_attempts' => max(1, (int) env('EXECUTION_EVIDENCE_UPLOAD_ATTEMPTS', 3)),

    // Radio de la geocerca de llegada, en metros: distancia máxima entre el GPS del escaneo de
    // prueba de entrega y el destino para considerarla "en el punto". Lo consume tracking/operación.
    'geofence_radius_meters' => (int) env('EXECUTION_GEOFENCE_RADIUS_METERS', 150),

    // Ids de claves públicas de etiquetas vigentes para la contraverificación de firma en el servidor
    // (P1-EXE-12). Formato CSV ("1,2"). La app verifica offline con el bundle completo de Labels; acá
    // solo se listan los ids aceptados por el gateway stub hasta enganchar el verificador real.
    'label_known_key_ids' => array_values(array_filter(array_map(
        static fn (string $id): int => (int) trim($id),
        explode(',', (string) env('EXECUTION_LABEL_KNOWN_KEY_IDS', '1')),
    ), static fn (int $id): bool => $id > 0)),

    // Claves públicas Ed25519 vigentes para la contraverificación REAL de la firma de etiquetas en el
    // servidor (P1-EXE-12), como mapa "id de clave => clave pública en base64". Son las mismas claves
    // públicas que la app Flutter usa offline (bundle de Labels); acá solo se verifica, nunca se firma.
    // Si este mapa viene vacío, el contexto cae al gateway stub (valida forma + id de clave conocido)
    // para poder operar sin claves cargadas. Formato del env: "1:<base64>,2:<base64>".
    'label_public_keys' => array_reduce(
        array_filter(array_map('trim', explode(',', (string) env('EXECUTION_LABEL_PUBLIC_KEYS', '')))),
        /**
         * @param  array<int, string> $carry
         * @return array<int, string>
         */
        static function (array $carry, string $pair): array {
            $parts = explode(':', $pair, 2);
            if (count($parts) === 2 && (int) trim($parts[0]) > 0 && trim($parts[1]) !== '') {
                $carry[(int) trim($parts[0])] = trim($parts[1]);
            }

            return $carry;
        },
        [],
    ),

    // Reacción automática ante un incidente de camión (avería/accidente) reportado por el chofer.
    'incident' => [
        // Minutos de retraso que se empujan a las ETAs de las paradas pendientes ante un incidente
        // MENOR (leve): la ruta continúa, pero llega más tarde. Un incidente MAYOR no usa esto: sus
        // paradas pendientes se reprograman para el día siguiente.
        'minor_delay_minutes' => (int) env('EXECUTION_INCIDENT_MINOR_DELAY_MINUTES', 120),
    ],
];
