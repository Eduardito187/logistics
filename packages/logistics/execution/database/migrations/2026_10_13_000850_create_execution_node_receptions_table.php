<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de recepciones en nodo: la conciliación de un envío que llega a un nodo destino por un corredor
 * interno (CD→CD, CD→tienda, tienda→tienda, tienda→CD). PK uuid string no incremental (UUID v7 del
 * dominio).
 *
 * Guarda el corredor, el nodo de origen y destino, los bultos esperados (jsonb) y el desenlace de la
 * conciliación (abierta/conciliada, cuándo se cerró). Los bultos recibidos viven en la tabla aparte
 * `execution_node_reception_units` (append-only). El índice único (shipment_id, destination_node_id)
 * garantiza una sola recepción por par envío+nodo destino.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('execution_node_receptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->index();
            $table->uuid('origin_node_id')->index();
            $table->uuid('destination_node_id')->index();
            $table->string('corridor')->index();
            $table->string('status')->index();
            $table->jsonb('expected_unit_ids');
            $table->timestampTz('opened_at');
            $table->timestampTz('reconciled_at')->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'destination_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('execution_node_receptions');
    }
};
