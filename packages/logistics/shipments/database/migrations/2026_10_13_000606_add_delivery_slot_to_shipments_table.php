<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega la franja de entrega elegida al envío: una fecha más una ventana horaria explícita
 * (inicio/fin en HH:MM). Las tres columnas son nullable: un envío recién recibido todavía no tiene
 * franja; se le asigna al reservar un slot de despacho (contexto capacity/promise).
 *
 * Se indexa `delivery_date` porque el calendario de despacho y la planificación diaria filtran los
 * envíos por el día comprometido; es el acceso caliente a esta tabla a escala.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            // Día comprometido de entrega (sin hora). Indexado: es la clave de barrido del calendario.
            $table->date('delivery_date')->nullable()->after('status')->index();
            // Ventana horaria elegida dentro de ese día, inicio y fin en 'HH:MM' (24h).
            $table->string('delivery_window_start', 5)->nullable()->after('delivery_date');
            $table->string('delivery_window_end', 5)->nullable()->after('delivery_window_start');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropIndex(['delivery_date']);
            $table->dropColumn(['delivery_date', 'delivery_window_start', 'delivery_window_end']);
        });
    }
};
