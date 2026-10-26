<?php

declare(strict_types=1);

/*
| Configuración del contexto Access. Hoy solo la política de retención/poda de la bitácora de auditoría
| (access_audit_log). Valores afinables por .env sin tocar código.
*/

return [
    // Poda de retención de la bitácora de auditoría de accesos (comando logistics:access:prune-audit-log).
    'prune' => [
        // Ventana de retención del log en días. Generosa por defecto: es registro de trazabilidad.
        'audit_log_retention_days' => (int) env('ACCESS_AUDIT_RETENTION_DAYS', 180),

        // Filas por lote de borrado (acota el bloqueo).
        'chunk' => (int) env('DB_PRUNE_CHUNK', 5000),

        // Pausa entre lotes en milisegundos (alivia la carga del motor en producción).
        'pause_ms' => (int) env('DB_PRUNE_PAUSE_MS', 100),
    ],
];
