<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de inspecciones pre-salida: el checklist ejecutado por un chofer sobre un vehículo, con el
 * resultado (apta / no apta) desnormalizado para filtrar en SQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleet_pre_trip_checklists', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('vehicle_id')->index();
            $table->uuid('driver_id')->index();
            $table->jsonb('items');
            $table->boolean('passed')->index();
            $table->date('performed_on')->index();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleet_pre_trip_checklists');
    }
};
