<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula una cuenta de usuario (login de la app) con un chofer de la flota.
 *
 * El usuario autenticado (`App\Models\User`, Sanctum) y el chofer (`fleet_drivers`, UUID) viven en
 * contextos distintos: Access/identidad vs. Fleet. Para que el chofer autenticado pueda leer SU
 * asignación del día no hace falta un contexto nuevo, sino decir "este login ES el chofer X".
 *
 * Guardamos esa referencia por VALOR (el UUID del chofer), igual que Fleet referencia vehículos por su
 * id sin clave foránea entre contextos (p. ej. `fleet_drivers.enabled_vehicle_ids`). ADITIVO y nullable:
 * las cuentas que no son choferes (supervisor, tienda, etc.) lo dejan en NULL y siguen válidas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            // UUID del chofer de Fleet (fleet_drivers.id). Sin FK: Fleet es otro bounded context.
            $table->uuid('driver_id')->nullable()->after('id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('driver_id');
        });
    }
};
