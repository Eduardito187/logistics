<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a tracking_shipments la ubicación del envío dentro de la ruta publicada del camión:
 *
 *  - route_id:       la ruta/plan del camión (id string, sin acoplar al contexto planning).
 *  - route_position: posición del envío en la secuencia de reparto (1-based: 1 es la primera parada).
 *
 * De la posición se deriva remaining_stops (paradas antes de la del cliente). El índice compuesto
 * (route_id, route_position) sirve al avance en cascada: cuando una parada se completa, se bajan las
 * paradas restantes de los envíos con posición posterior en la misma ruta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_shipments', function (Blueprint $table): void {
            $table->uuid('route_id')->nullable()->after('remaining_stops');
            $table->unsignedInteger('route_position')->nullable()->after('route_id');
            $table->index(['route_id', 'route_position']);
        });
    }

    public function down(): void
    {
        Schema::table('tracking_shipments', function (Blueprint $table): void {
            $table->dropIndex(['route_id', 'route_position']);
            $table->dropColumn(['route_id', 'route_position']);
        });
    }
};
