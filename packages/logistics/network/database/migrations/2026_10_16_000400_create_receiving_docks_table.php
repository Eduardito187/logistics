<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Andenes de recepción de los almacenes centrales (roadmap marketplace §5, paso 1). Por dónde entra la
 * mercadería de proveedores (inbound/ASN). Aditivo: no toca los nodos ni ninguna tabla existente.
 *
 * Guarda el almacén (nodo), el código humano del andén (único por almacén), su nombre, la capacidad
 * nominal (citas por franja) y si está operativo. El agendamiento de cupos por día/franja NO vive aquí:
 * lo lleva el contexto capacity (eje inbound); esta tabla solo declara el andén y su capacidad física.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('receiving_docks')) {
            return;
        }

        Schema::create('receiving_docks', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Almacén central (nodo de network). Se guarda como id, sin FK cruzada entre contextos.
            $table->uuid('warehouse_id')->index();
            $table->string('code');
            $table->string('name');
            $table->unsignedInteger('appointments_per_slot');
            $table->boolean('active')->default(true)->index();
            $table->timestamps();

            // Clave humana del andén: único dentro de su almacén.
            $table->unique(['warehouse_id', 'code'], 'receiving_docks_warehouse_code_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('receiving_docks');
    }
};
