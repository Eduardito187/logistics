<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enriquece a los vehículos con su ficha técnica (marca, modelo, año, VIN, nº de motor, color) y las
 * fotos de sus cuatro lados. Todo ADITIVO y nullable: los vehículos ya registrados siguen válidos sin
 * estos datos, que se completan por separado con el comando de perfil (UpdateVehicleProfile).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_vehicles', function (Blueprint $table): void {
            $table->string('make', 80)->nullable()->after('plate');
            $table->string('model', 80)->nullable()->after('make');
            $table->smallInteger('model_year')->nullable()->after('model');
            $table->string('vin', 40)->nullable()->after('model_year');
            $table->string('engine_number', 40)->nullable()->after('vin');
            $table->string('color', 40)->nullable()->after('engine_number');
            // Rutas (clave del objeto en disco) de las fotos de los cuatro lados del vehículo.
            $table->string('photo_front', 1024)->nullable()->after('color');
            $table->string('photo_back', 1024)->nullable()->after('photo_front');
            $table->string('photo_left', 1024)->nullable()->after('photo_back');
            $table->string('photo_right', 1024)->nullable()->after('photo_left');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_vehicles', function (Blueprint $table): void {
            $table->dropColumn([
                'make',
                'model',
                'model_year',
                'vin',
                'engine_number',
                'color',
                'photo_front',
                'photo_back',
                'photo_left',
                'photo_right',
            ]);
        });
    }
};
