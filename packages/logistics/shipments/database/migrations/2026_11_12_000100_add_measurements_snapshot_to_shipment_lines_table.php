<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto de medidas por unidad en la línea del envío (Roadmap 02, Fase 2.1): peso (kg), volumen (m³) y la
 * fuente de la que salieron (product | category | default). Se toma al recibir el envío y no se
 * recalcula. CONTRATO con planning: nombres y tipos exactos, todas nullable (null = línea histórica aún
 * sin backfill; planning cae a sus defaults de config).
 *
 * Aditiva e idempotente (guardas hasTable/hasColumn); no toca datos. El relleno de líneas existentes lo
 * hace el comando `shipments:backfill-line-measurements` (solo completa nulls).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('shipment_lines')) {
            return;
        }

        $addWeight = !Schema::hasColumn('shipment_lines', 'unit_weight_kg');
        $addVolume = !Schema::hasColumn('shipment_lines', 'unit_volume_m3');
        $addSource = !Schema::hasColumn('shipment_lines', 'measurements_source');

        if (!$addWeight && !$addVolume && !$addSource) {
            return;
        }

        Schema::table('shipment_lines', function (Blueprint $table) use ($addWeight, $addVolume, $addSource): void {
            if ($addWeight) {
                $table->decimal('unit_weight_kg', 10, 3)->nullable();
            }
            if ($addVolume) {
                $table->decimal('unit_volume_m3', 12, 6)->nullable();
            }
            if ($addSource) {
                $table->string('measurements_source', 16)->nullable();
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('shipment_lines')) {
            return;
        }

        $existing = array_values(array_filter(
            ['unit_weight_kg', 'unit_volume_m3', 'measurements_source'],
            static fn (string $column): bool => Schema::hasColumn('shipment_lines', $column),
        ));

        if ($existing !== []) {
            Schema::table('shipment_lines', function (Blueprint $table) use ($existing): void {
                $table->dropColumn($existing);
            });
        }
    }
};
