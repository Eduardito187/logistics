<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla del read model de seguimiento: el estado visible de cada envío más la última posición del
 * camión. Un seguimiento por envío; el token es la clave pública (opaca) del enlace de rastreo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_shipments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->unique();
            $table->string('token')->unique();
            $table->string('status')->index();
            $table->unsignedInteger('remaining_stops')->default(0);
            $table->timestampTz('eta')->nullable();
            $table->decimal('last_lat', 10, 7)->nullable();
            $table->decimal('last_lng', 10, 7)->nullable();
            $table->timestampTz('last_position_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_shipments');
    }
};
