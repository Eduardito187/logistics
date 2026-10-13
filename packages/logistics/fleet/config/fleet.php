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

    // Reglas por defecto de turnos y descansos de la tripulación. Son el respaldo: settings puede
    // sobreescribirlas (versionadas) vía SettingsShiftRulesPolicy. Expresadas en horas por comodidad.
    'shift' => [
        // Jornada máxima de un turno.
        'max_daily_hours' => (float) env('FLEET_SHIFT_MAX_DAILY_HOURS', 10),
        // Ventana horaria permitida del día (hora de inicio más temprana y hora de fin más tardía).
        'earliest_start_hour' => (float) env('FLEET_SHIFT_EARLIEST_START_HOUR', 5),
        'latest_end_hour' => (float) env('FLEET_SHIFT_LATEST_END_HOUR', 22),
        // Descanso mínimo entre dos turnos de una misma persona.
        'min_rest_hours' => (float) env('FLEET_SHIFT_MIN_REST_HOURS', 10),
    ],
];
