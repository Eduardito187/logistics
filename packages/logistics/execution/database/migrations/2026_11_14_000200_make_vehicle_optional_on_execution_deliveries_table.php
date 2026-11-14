<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Entrega de MOSTRADOR (retiro en tienda): el cliente retira su pedido en la tienda y el personal lo
 * entrega con firma y foto, sin camión ni ruta. Esa entrega no tiene vehículo, así que `vehicle_id` deja de
 * ser obligatorio. Aditiva e idempotente: solo relaja el NOT NULL si todavía está puesto; no toca filas.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('execution_deliveries', 'vehicle_id') || DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE execution_deliveries ALTER COLUMN vehicle_id DROP NOT NULL');
    }

    public function down(): void
    {
        // Sin vuelta atrás a propósito: reponer el NOT NULL fallaría con entregas de mostrador ya guardadas.
    }
};
