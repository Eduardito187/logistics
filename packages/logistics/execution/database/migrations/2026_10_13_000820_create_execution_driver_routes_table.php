<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de rutas del chofer: la hoja de ruta de un camión para un día (secuencia ordenada de todas sus
 * paradas). PK uuid string no incremental (UUID v7 del dominio). Una ruta por camión y día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_driver_routes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_id')->index();
            $table->date('route_date');
            $table->string('status')->index();
            $table->timestamps();

            // Una sola ruta por camión y día.
            $table->unique(['vehicle_id', 'route_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_driver_routes');
    }
};
