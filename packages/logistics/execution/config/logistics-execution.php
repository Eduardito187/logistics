<?php

declare(strict_types=1);

// Configuración del contexto Ejecución. Valores por env; sin env, defaults seguros para desarrollo.
return [
    // Disco S3-compatible donde se guardan las fotos de evidencia (prueba de entrega y daños).
    // Debe existir en config/filesystems.php; por defecto, el disco cloud del proyecto.
    'pod_disk' => env('EXECUTION_POD_DISK', env('FILESYSTEM_CLOUD', 's3')),

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

    // Reacción automática ante un incidente de camión (avería/accidente) reportado por el chofer.
    'incident' => [
        // Minutos de retraso que se empujan a las ETAs de las paradas pendientes ante un incidente
        // MENOR (leve): la ruta continúa, pero llega más tarde. Un incidente MAYOR no usa esto: sus
        // paradas pendientes se reprograman para el día siguiente.
        'minor_delay_minutes' => (int) env('EXECUTION_INCIDENT_MINOR_DELAY_MINUTES', 120),
    ],
];
