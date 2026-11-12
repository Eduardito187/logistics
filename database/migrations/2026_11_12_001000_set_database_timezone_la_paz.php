<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Alinea la zona horaria por defecto de la BASE con la app (America/La_Paz).
 *
 * Por qué a nivel base y no solo en la conexión: el runtime va por PgBouncer (transaction pooling), donde
 * un `SET TIME ZONE` por conexión se descarta entre transacciones. `ALTER DATABASE … SET timezone` fija el
 * default de CADA sesión que abra el pooler, sin depender del `SET`. La conexión también lo pone
 * (config/database.php `timezone`) para los accesos directos (tests, migraciones).
 *
 * Idempotente: re-ejecutar fija el mismo valor. Fuera de transacción por prolijidad (no es DDL de tabla).
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (!is_string($database) || $database === '') {
            return;
        }

        // El nombre de la base no viene del usuario (es de config); se cita igual para nombres con guiones.
        DB::statement('ALTER DATABASE "'.str_replace('"', '""', $database).'" SET timezone TO '."'America/La_Paz'");
    }

    public function down(): void
    {
        $database = DB::connection()->getDatabaseName();
        if (!is_string($database) || $database === '') {
            return;
        }

        DB::statement('ALTER DATABASE "'.str_replace('"', '""', $database).'" RESET timezone');
    }
};
