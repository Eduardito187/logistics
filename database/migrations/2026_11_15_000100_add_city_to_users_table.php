<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ciudad (departamento de Bolivia) a la que pertenece cada persona de la app, sea del rol que sea.
 *
 * Guarda el CÓDIGO del departamento, el mismo que usa `network_nodes.department` (`la_paz`, `santa_cruz`,
 * `cochabamba`, `oruro`, `potosi`, `chuquisaca`, `tarija`, `beni`, `pando`). null = persona sin ciudad
 * asignada. Regla de consistencia: si la persona tiene tienda / nodo (`users.node_id`), su ciudad es el
 * departamento de ese nodo (la aplica la ficha del personal al asignar el nodo).
 *
 * Es el dato con el que el servidor acota qué pedidos lista y opera cada rol. Lo existente se completa
 * con `php artisan logistics:staff:backfill-city --force`.
 *
 * ADITIVA, nullable e idempotente (no falla si la columna ya existe).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('users', 'city')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->string('city', 40)->nullable()->index();
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('users', 'city')) {
            return;
        }

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['city']);
            $table->dropColumn('city');
        });
    }
};
