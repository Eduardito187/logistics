<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapa de traducción del conector: sku propio ↔ coordenada SAP (material + centro + almacén).
 *
 * Es la tabla de configuración de la capa anti-corrupción (ADR 002). El sku es único: un sku propio
 * se traduce a una sola coordenada SAP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sap_material_mappings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('sku')->unique();
            $table->string('sap_material')->index();
            $table->string('center');
            $table->string('warehouse');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_material_mappings');
    }
};
