<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de escala para los accesos calientes de envíos (frente E — escala).
 *
 * Son índices ADITIVOS: no cambian el esquema de datos, solo aceleran las consultas de lectura que
 * ya existen a volumen. Se agregan los que faltan sobre los filtros/ordenamientos reales:
 *
 *  - (delivery_date, status): el calendario de despacho barre un día y suele acotar por estado; el
 *    índice simple de `delivery_date` no cubre el segundo predicado. Compuesto en ese orden porque la
 *    fecha es siempre igualdad y el estado filtra dentro del día.
 *  - (customer_name, created_at): la ficha del cliente lista "otros pedidos del mismo cliente" por
 *    nombre y ordenados por fecha de alta descendente (ShipmentResource). El compuesto sirve el filtro
 *    y el orden de una sola pasada, y además acelera la búsqueda global por nombre (prefijo).
 *
 * `source`, `status`, `delivery_date` y el único (source, external_id) ya están indexados en sus
 * migraciones de creación, así que no se repiten aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->index(['delivery_date', 'status'], 'shipments_delivery_date_status_index');
            $table->index(['customer_name', 'created_at'], 'shipments_customer_name_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex('shipments_delivery_date_status_index');
            $table->dropIndex('shipments_customer_name_created_at_index');
        });
    }
};
