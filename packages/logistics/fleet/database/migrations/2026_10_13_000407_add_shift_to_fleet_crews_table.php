<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fleet_crews', function (Blueprint $table): void {
            // Turno de trabajo de la tripulación (horas reales de salida y regreso). Nulo cuando la
            // tripulación se arma sin fijar horas: en ese caso no se validan reglas de turnos.
            $table->timestampTz('shift_starts_at')->nullable()->after('assembled_for');
            $table->timestampTz('shift_ends_at')->nullable()->after('shift_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('fleet_crews', function (Blueprint $table): void {
            $table->dropColumn(['shift_starts_at', 'shift_ends_at']);
        });
    }
};
