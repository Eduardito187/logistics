<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensión de seller en el mapa de traducción sku ↔ coordenada SAP (ADR 017, roadmap marketplace §6.4).
 *
 * Es aditiva: `seller_id` nace nullable. El mapeo propio de Dismac (seller nulo) sigue igual y conserva
 * su unicidad por `sku`; un seller nulo o no nulo distingue la coordenada propia de la de consignación.
 * No se toca el índice único de `sku` (los SKUs de seller son distintos de los propios), para mantener
 * la migración estrictamente aditiva y el flujo retail intacto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sap_material_mappings', function (Blueprint $table): void {
            $table->uuid('seller_id')->nullable()->index()->after('warehouse');
        });
    }

    public function down(): void
    {
        Schema::table('sap_material_mappings', function (Blueprint $table): void {
            $table->dropColumn('seller_id');
        });
    }
};
