<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega al plan la GEOMETRÍA de la ruta por calles calculada en el servidor: la polilínea GeoJSON
 * (columna jsonb) y la distancia y la duración TOTALES del recorrido. Así el mapa la pinta tal cual, sin
 * depender del navegador ni volver a consultar el ruteador, y los totales quedan disponibles para KPIs.
 *
 * Todo es opcional (nulo) hasta que la corrida de ruteo la calcula y la persiste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planning_plans', function (Blueprint $table): void {
            $table->jsonb('route_geometry')->nullable()->after('status');
            $table->double('route_distance_meters')->nullable()->after('route_geometry');
            $table->double('route_duration_seconds')->nullable()->after('route_distance_meters');
        });
    }

    public function down(): void
    {
        Schema::table('planning_plans', function (Blueprint $table): void {
            $table->dropColumn(['route_geometry', 'route_distance_meters', 'route_duration_seconds']);
        });
    }
};
