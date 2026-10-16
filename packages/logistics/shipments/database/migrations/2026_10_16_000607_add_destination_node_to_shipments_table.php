<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Nodo destino del envío (UUID de `network_nodes`), aditivo y NULLABLE.
 *
 * Es el único vínculo envío→nodo disponible ANTES de la recepción (la tabla de recepción en nodo solo
 * nace al recibir, así que no sirve para filtrar el listado previo). Permite acotar la lista de envíos a
 * la tienda del usuario (rol Tienda) server-side.
 *
 * Mientras el ingest/SAP no lo pueble queda null; para el rol Tienda el filtro forzado hará que vea SOLO
 * sus envíos (y ninguno si aún no hay dato): nunca expone envíos de otras tiendas (seguro por defecto).
 *
 * Idempotente: solo agrega la columna/índice si no existen (reejecutar la migración no falla).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            if (!Schema::hasColumn('shipments', 'destination_node_id')) {
                $table->uuid('destination_node_id')->nullable()->index()->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            if (Schema::hasColumn('shipments', 'destination_node_id')) {
                $table->dropColumn('destination_node_id');
            }
        });
    }
};
