<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula una cuenta de usuario (login de la app) con un AYUDANTE de la flota (rol tripulante).
 *
 * Igual que `users.driver_id` liga el login con un chofer, `users.helper_id` liga el login con un ayudante
 * (`fleet_helpers`, UUID): así el tripulante autenticado puede leer SU tripulación del día (my-crew) y la
 * hoja de ruta de su camión, resolviéndose por pertenencia a `fleet_crews.helper_ids`. Es una referencia
 * por VALOR (el UUID del ayudante), sin clave foránea entre contextos (Access vs. Fleet), como el resto de
 * los vínculos cruzados de la plataforma.
 *
 * ADITIVA, nullable e IDEMPOTENTE: las cuentas que no son ayudantes (chofer, tienda, oficina, etc.) la
 * dejan en NULL y siguen válidas; solo agrega la columna si la tabla existe y la columna todavía no está.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('users') || Schema::hasColumn('users', 'helper_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            // UUID del ayudante de Fleet (fleet_helpers.id). Sin FK: Fleet es otro bounded context.
            $table->uuid('helper_id')->nullable()->after('driver_id')->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('users') && Schema::hasColumn('users', 'helper_id')) {
            Schema::table('users', function (Blueprint $table): void {
                $table->dropColumn('helper_id');
            });
        }
    }
};
