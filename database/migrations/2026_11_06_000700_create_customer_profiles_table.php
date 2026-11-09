<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C11 — Proyección de lectura CUSTOMER CANÓNICO: un cliente por DOCUMENTO (CI/NIT) normalizado.
 *
 * Hoy la identidad del cliente está dispersa (shipments.customer_name, commerce_orders.ship_to->>name);
 * el Cliente 360 agrupa por NOMBRE, que no es único. Esta tabla materializa un cliente canónico cuya
 * CLAVE es el documento normalizado (mayúsculas, sin separadores), agregando lo que se sabe de él. Es una
 * PROYECCIÓN: se reconstruye por completo desde los datos fuente (comando customers:rebuild) y se mantiene
 * por eventos; nunca es la fuente de verdad, así que es seguro recomputarla.
 *
 * Aditiva e idempotente (create si no existe). No reemplaza el camino por nombre del Cliente 360: lo
 * complementa cuando el envío trae documento.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('customer_profiles')) {
            return;
        }

        Schema::create('customer_profiles', function (Blueprint $table): void {
            // Clave canónica: el documento (CI/NIT) normalizado a mayúsculas sin separadores. NO es un UUID;
            // es el documento real del cliente, que SÍ puede mostrarse.
            $table->string('document_id')->primary();
            $table->string('name');
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->unsignedInteger('shipments_count')->default(0);
            $table->timestampTz('first_seen_at')->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestamps();

            // Búsqueda por nombre (⌘K / directorio) y orden por actividad reciente.
            $table->index('name');
            $table->index('last_seen_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_profiles');
    }
};
