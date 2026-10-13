<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de paradas de la ruta del chofer: lo que el camión deja en cada punto, en orden de reparto.
 *
 * Lleva una foto legible del destino (referencia del pedido, cliente, dirección, ETA y coordenadas) que
 * viene del plan publicado, para que el chofer sepa qué baja en cada parada sin consultar otros
 * contextos. `settled_at` guarda cuándo se cerró la parada (entregada o fallida).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_driver_route_stops', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('route_id')->index();
            $table->uuid('shipment_id')->index();
            $table->unsignedInteger('sequence');
            $table->string('order_reference')->nullable();
            $table->string('customer_name')->nullable();
            $table->string('address')->nullable();
            $table->timestampTz('eta')->nullable();
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            $table->string('status')->index();
            $table->timestampTz('settled_at')->nullable();
            $table->timestamps();

            // Un envío no puede aparecer dos veces en la misma ruta, ni dos paradas compartir posición.
            $table->unique(['route_id', 'shipment_id']);
            $table->unique(['route_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_driver_route_stops');
    }
};
