<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Campos GPS del último reporte de posición del camión (contrato con la app Flutter): velocidad (m/s),
 * rumbo (grados 0..360) y precisión (metros). Son OPCIONALES: la app los manda cuando el dispositivo los
 * entrega; si no vienen, la ingesta deriva velocidad y rumbo de dos posiciones seguidas. Aditivo: columnas
 * nullable, sin tocar datos existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tracking_shipments', function (Blueprint $table): void {
            // Velocidad instantánea del reporte en metros por segundo (no negativa).
            $table->decimal('last_speed_mps', 8, 3)->nullable()->after('last_position_at');
            // Rumbo del reporte en grados desde el norte (0..360).
            $table->decimal('last_heading_deg', 6, 2)->nullable()->after('last_speed_mps');
            // Precisión horizontal informada por el GPS en metros (no negativa).
            $table->decimal('last_accuracy_m', 8, 2)->nullable()->after('last_heading_deg');
        });
    }

    public function down(): void
    {
        Schema::table('tracking_shipments', function (Blueprint $table): void {
            $table->dropColumn(['last_speed_mps', 'last_heading_deg', 'last_accuracy_m']);
        });
    }
};
