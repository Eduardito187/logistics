<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de TRAMOS de un envío (modelo hub-and-spoke): un envío puede recorrer varios tramos ordenados
 * (p. ej. almacén SCZ → almacén Tarija → cliente) y cada tramo lo mueve la flota propia o un tercero.
 *
 * El vínculo con el envío es por `shipment_id` (la misma identidad del agregado Shipment), sin FK de base:
 * cada contexto/agregado queda autónomo y el tramo es extraíble a su propio servicio (ADR 001/008). El
 * envío conserva su `origin_node_id`/`destination_node_id` como primer origen / destino final; esta tabla
 * guarda el detalle del camino entre esos extremos.
 *
 * Pensada para MILLONES de filas: PK uuid v7 (ordenable en el tiempo, índice compacto), único natural
 * (shipment_id, seq) para cargar el itinerario en orden y garantizar una sola fila por posición, e índices
 * sobre los accesos calientes de las olas siguientes (destino del tramo, estado, transportadora).
 *
 * Aditiva e idempotente: la tabla y cada índice se crean solo si faltan, así correr la migración más de
 * una vez no falla ni cambia nada.
 */
return new class extends Migration
{
    private const array INDEXES = [
        'to_node_id' => 'shipment_legs_to_node_id_index',
        'status' => 'shipment_legs_status_index',
        'carrier_id' => 'shipment_legs_carrier_id_index',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('shipment_legs')) {
            Schema::create('shipment_legs', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('shipment_id')->index();
                $table->unsignedInteger('seq');
                $table->uuid('from_node_id')->nullable();
                $table->uuid('to_node_id')->nullable();
                $table->string('from_department', 40)->nullable();
                $table->string('to_department', 40)->nullable();
                // 'own_fleet' | 'third_party' (ver HaulageMode). La DECISIÓN propio/tercero es de olas posteriores.
                $table->string('haulage_mode', 20)->default('own_fleet');
                $table->uuid('carrier_id')->nullable();
                // 'pending' | 'in_transit' | 'closed' (ver LegStatus).
                $table->string('status', 20)->default('pending');
                $table->timestampTz('departed_at')->nullable();
                $table->timestampTz('arrived_at')->nullable();
                $table->timestampsTz();

                // Un solo tramo por posición dentro del envío; carga el itinerario en orden de una pasada.
                $table->unique(['shipment_id', 'seq'], 'shipment_legs_shipment_id_seq_unique');
            });
        }

        Schema::table('shipment_legs', function (Blueprint $table): void {
            foreach (self::INDEXES as $column => $index) {
                if (!Schema::hasIndex('shipment_legs', $index)) {
                    $table->index($column, $index);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_legs');
    }
};
