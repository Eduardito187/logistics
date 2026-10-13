<?php

declare(strict_types=1);

/*
| Configuración del bounded context Fleet (flota y tripulación).
|
| Se fusiona con mergeConfigFrom en el ServiceProvider, de modo que el proyecto puede sobreescribir
| estos valores por env sin tocar el package.
*/

return [
    // Disco (filesystem de Laravel) donde se guardan los archivos escaneados de los documentos de
    // flota (SOAT, inspección técnica, pólizas). En producción apunta a S3 (ver docs/architecture.md).
    'documents_disk' => env('FLEET_DOCUMENTS_DISK', 's3'),

    // Ventana por defecto, en días, del tablero de documentos por vencer (alertas de vencimiento).
    'expiry_alert_days' => (int) env('FLEET_EXPIRY_ALERT_DAYS', 30),
];
