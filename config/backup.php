<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Backups de la base PostgreSQL (backup:create / backup:verify / backup:restore, db:backup / db:restore)
|--------------------------------------------------------------------------
|
| El backup respalda SOLO la base de datos (estructura completa + todos los datos). No incluye archivos
| subidos, volúmenes, contenedores ni imágenes (ver docs/backup-restore.md).
|
| Conexión DIRECTA a Postgres: dump/restore NO deben pasar por PgBouncer (transaction pooling no soporta
| snapshots exportados ni el DDL de sesión del swap). Orden de resolución del host/puerto:
|   1. --host / --port del comando,
|   2. DB_DIRECT_HOST / DB_DIRECT_PORT,
|   3. el host/puerto de la conexión; si parece PgBouncer (puerto 6432 o host *bouncer*), se descubre el
|      Postgres real preguntándole al servidor (`inet_server_addr()`), con un aviso para fijar DB_DIRECT_HOST.
*/

return [
    // Directorio por defecto de los bundles (`logistics-<YYYYmmdd-HHMMSS>.tar`).
    'path' => env('BACKUP_PATH', storage_path('app/backups')),

    // Host/puerto directos de Postgres (los mismos que usa la conexión `pgsql_direct`).
    'direct_host' => env('DB_DIRECT_HOST'),
    'direct_port' => env('DB_DIRECT_PORT'),

    // Base de mantenimiento del mismo servidor desde la que se hace el DDL (CREATE/DROP/RENAME DATABASE):
    // no se puede renombrar la base a la que uno está conectado.
    'maintenance_database' => env('BACKUP_MAINTENANCE_DATABASE', 'postgres'),

    // Timeout (segundos) de pg_dump / pg_restore / tar.
    'timeout' => (int) env('BACKUP_TIMEOUT', 7200),
];
