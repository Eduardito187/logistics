<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Momento en que una parada se SUMÓ a la ruta por una asignación explícita (alguien asignó el pedido al
 * camión). Las paradas que vienen del plan publicado lo dejan en null.
 *
 * Es el dato que permite aplicar la regla de la ruta del día: contiene las paradas del plan publicado de ese
 * camión y día más las que se le sumaron ESE día; un pedido de otro día no se arrastra solo. Sin esta
 * columna no se podía saber cuándo entró una parada (las filas se regeneran en cada guardado).
 *
 * Aditiva e idempotente: columna nullable, no toca filas.
 */
return new class extends Migration
{
    private const string TABLE = 'execution_driver_route_stops';

    private const string COLUMN = 'attached_at';

    public function up(): void
    {
        if (!Schema::hasTable(self::TABLE) || Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->timestampTz(self::COLUMN)->nullable();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable(self::TABLE) || !Schema::hasColumn(self::TABLE, self::COLUMN)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->dropColumn(self::COLUMN);
        });
    }
};
