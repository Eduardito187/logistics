<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vincula una cuenta de usuario (login de la app) con un transportista tercero (contexto Carriers).
 *
 * Regla de negocio: un usuario con rol transportista pertenece a UN transportista. Es la identidad
 * SERVER-SIDE del transportista: los endpoints de liquidaciones/tarifas acotan por esta columna y
 * nunca por un `carrier_id` enviado por el cliente (antes la app elegía "quién soy" de una lista de
 * TODOS los transportistas y el backend le creía).
 *
 * Igual que `users.node_id` (tienda) y `users.driver_id` (chofer): referencia por VALOR (UUID de
 * `carriers.id`), SIN clave foránea dura, para que cada contexto siga siendo extraíble a su servicio.
 * ADITIVO, nullable e idempotente (no falla si la columna ya existe).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'carrier_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            // UUID del transportista (carriers.id). Sin FK: Carriers es otro bounded context.
            $table->uuid('carrier_id')->nullable()->after('node_id')->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'carrier_id')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['carrier_id']);
            $table->dropColumn('carrier_id');
        });
    }
};
