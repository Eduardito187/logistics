<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para el lado de LECTURA del listado de envíos (WS1 — escala del read-side de shipments).
 *
 * El listado (ListShipments / ListLowConfidenceAddresses) ahora empuja sus filtros a SQL. Estos índices
 * ADITIVOS cubren los predicados calientes que aún no tenían soporte compuesto:
 *
 *  - (destination_node_id, status): el rol Tienda FUERZA el filtro por su nodo y suele acotar además por
 *    estado. El índice simple de `destination_node_id` (migración 000607) no cubre el segundo predicado;
 *    este compuesto resuelve ambos de una pasada y, por prefijo izquierdo, también el filtro solo por nodo.
 *  - (address_confidence_level): ListLowConfidenceAddresses filtra por este nivel ('LOW'); la columna no
 *    estaba indexada y antes se barría toda la tabla en memoria.
 *
 * `source`, `status`, `delivery_date`, el único (source, external_id) y (destination_node_id) ya están
 * indexados en sus migraciones previas, así que no se repiten aquí (cubren los filtros simples por estado
 * y fuente del listado).
 *
 * Idempotente: cada índice se crea solo si no existe (reejecutar la migración no falla). Solo toca la
 * tabla `shipments`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            if (!Schema::hasIndex('shipments', 'shipments_destination_node_id_status_index')) {
                $table->index(['destination_node_id', 'status'], 'shipments_destination_node_id_status_index');
            }

            if (!Schema::hasIndex('shipments', 'shipments_address_confidence_level_index')) {
                $table->index('address_confidence_level', 'shipments_address_confidence_level_index');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            if (Schema::hasIndex('shipments', 'shipments_destination_node_id_status_index')) {
                $table->dropIndex('shipments_destination_node_id_status_index');
            }

            if (Schema::hasIndex('shipments', 'shipments_address_confidence_level_index')) {
                $table->dropIndex('shipments_address_confidence_level_index');
            }
        });
    }
};
