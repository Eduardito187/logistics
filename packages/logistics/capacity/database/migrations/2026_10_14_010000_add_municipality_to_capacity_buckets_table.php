<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega el eje MUNICIPIO al cupo (aditivo). El municipio pasa a ser el eje principal de gestión de
 * cupos (el operador activa cupos por municipio y rango de fechas); nodo/zona quedan para control fino
 * y compatibilidad. Es un UUID del contexto coverage (un área administrativa de nivel municipio); se
 * guarda como id, sin FK cruzada entre contextos.
 *
 * Un cupo refiere a EXACTAMENTE uno de los tres ejes (municipio, zona o nodo); esa regla la aplica el
 * dominio (BucketScope) y la refuerzan los índices únicos parciales de la migración siguiente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('capacity_buckets', function (Blueprint $table): void {
            $table->uuid('municipality_id')->nullable()->after('zone_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('capacity_buckets', function (Blueprint $table): void {
            $table->dropColumn('municipality_id');
        });
    }
};
