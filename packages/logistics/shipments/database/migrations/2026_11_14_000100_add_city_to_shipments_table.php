<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CIUDAD del envío como campo propio. Antes la ciudad solo existía dentro del texto libre de la dirección,
 * así que filtrar o reprogramar "por ciudad" dependía de cómo estuviera escrita. Ahora se guarda el nombre
 * canónico (p. ej. «Santa Cruz») y se filtra por la columna indexada. Se completa al guardar cada envío y,
 * para los existentes, con `logistics:shipments:backfill-city`. Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shipments') || Schema::hasColumn('shipments', 'city')) {
            return;
        }

        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('city', 80)->nullable()->index();
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('shipments', 'city')) {
            Schema::table('shipments', function (Blueprint $table): void {
                $table->dropColumn('city');
            });
        }
    }
};
