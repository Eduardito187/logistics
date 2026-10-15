<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla del read model per-bulto del seguimiento: el estado visible de cada bulto (unidad física) de
 * un envío, su última ubicación conocida (nodo/parada) y el instante del último escaneo.
 *
 * Un seguimiento por bulto (unit_id único). El índice por shipment_id sirve a la vista de detalle y a
 * la cascada del desenlace del envío a todos sus bultos.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_units', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->index();
            $table->uuid('unit_id')->unique();
            $table->string('status')->index();
            // Nodo/parada donde se vio por última vez el bulto (id string; sin acoplar a network).
            $table->string('last_location_id')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_units');
    }
};
