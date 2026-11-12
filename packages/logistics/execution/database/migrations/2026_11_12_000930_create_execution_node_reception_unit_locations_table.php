<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ubicación en estante VIGENTE de cada producto recibido en un nodo (aditivo e idempotente).
 *
 * Una fila por (recepción, producto): una reubicación reescribe la fila (la historia queda en el evento
 * `execution.node_unit_shelved` del outbox). `node_id` se desnormaliza para buscar "qué hay en el estante X
 * del nodo Y" y `unit_id` se indexa para que tracking resuelva en lote la ubicación de los productos.
 */
return new class extends Migration
{
    private const string TABLE = 'execution_node_reception_unit_locations';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            return;
        }

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('node_reception_id')->index();
            $table->uuid('node_id');
            $table->string('unit_id')->index();
            $table->string('location', 64);
            $table->timestampTz('located_at');
            $table->string('device_id');
            $table->string('user_id');
            $table->timestamps();

            $table->unique(['node_reception_id', 'unit_id'], 'execution_nr_unit_locations_reception_unit_unique');
            $table->index(['node_id', 'location'], 'execution_nr_unit_locations_node_location_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TABLE);
    }
};
