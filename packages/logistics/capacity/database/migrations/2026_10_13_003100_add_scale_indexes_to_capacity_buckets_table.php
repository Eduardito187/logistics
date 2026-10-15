<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de escala para los cupos (frente E — escala). Aditivos: solo aceleran lectura.
 *
 *  - (zone_id, slot_date, unit): la disponibilidad que consume promise filtra por zona + día + unidad
 *    (`deliveries`). El único natural arranca por node_id, así que no sirve para el barrido por zona;
 *    este compuesto sí. node_id va NULL en los buckets de zona, por eso no entra en la clave.
 *  - (node_id, slot_date, unit): simétrico para los cupos de nodo (los de zona van NULL aquí).
 *
 * `slot_date`, `node_id`, `zone_id` y el único natural (node_id, zone_id, slot_date, time_slot, unit)
 * ya existen desde la creación; estos compuestos cubren el acceso por scope + fecha + unidad.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capacity_buckets', function (Blueprint $table): void {
            $table->index(['zone_id', 'slot_date', 'unit'], 'capacity_buckets_zone_date_unit_index');
            $table->index(['node_id', 'slot_date', 'unit'], 'capacity_buckets_node_date_unit_index');
        });
    }

    public function down(): void
    {
        Schema::table('capacity_buckets', function (Blueprint $table): void {
            $table->dropIndex('capacity_buckets_zone_date_unit_index');
            $table->dropIndex('capacity_buckets_node_date_unit_index');
        });
    }
};
