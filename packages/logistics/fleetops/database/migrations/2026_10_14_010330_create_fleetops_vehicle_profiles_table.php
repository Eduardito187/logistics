<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fleetops_vehicle_profiles', function (Blueprint $table): void {
            // PK = id del vehículo en fleet (texto), relación 1:1 sin FK dura (contexto desacoplable).
            $table->string('vehicle_id')->primary();
            // UUID interno del perfil: identidad estable de los eventos de dominio del perfil.
            $table->uuid('id')->unique();
            $table->string('brand')->nullable();
            $table->string('model')->nullable();
            $table->smallInteger('year')->nullable();
            $table->string('fuel_type')->nullable();
            // Capacidad del tanque en litros.
            $table->decimal('tank_liters', 7, 2)->nullable();
            $table->string('vin')->nullable();
            $table->string('color')->nullable();
            // Odómetro vigente en kilómetros enteros (solo avanza).
            $table->integer('current_odometer_km')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fleetops_vehicle_profiles');
    }
};
