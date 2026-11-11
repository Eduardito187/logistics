<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro central de impresoras de red por nodo (2026-11-11, pedido de la app).
 *
 * Por qué: en sedes con WiFi y cable en redes separadas, el teléfono no puede descubrir la impresora
 * (multicast/broadcast no cruza de red). Se registra una vez aquí (host/IP + puerto) y la app la ofrece
 * en "Impresoras de tu sede" vía GET /api/v1/app/printers. Cambiar la IP en el admin se refleja en la app
 * sin reinstalar.
 *
 * Aditiva e idempotente: no toca ninguna tabla existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('label_printers')) {
            return;
        }

        Schema::create('label_printers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Nombre visible ("Zebra Andén 2") y modelo opcional ("ZT411").
            $table->string('name', 120);
            $table->string('model', 120)->nullable();
            // Destino de red: IP o hostname + puerto RAW (9100 por defecto en Zebra).
            $table->string('host', 255);
            $table->unsignedInteger('port')->default(9100);
            // Lenguaje de impresión: zpl | other.
            $table->string('language', 20)->default('zpl');
            // Nodo de red (sede) al que pertenece; null = compartida (visible para todos).
            $table->uuid('node_id')->nullable()->index();
            // Ubicación física dentro de la sede ("Andén 2", "Mostrador").
            $table->string('location', 120)->nullable();
            $table->boolean('active')->default(true)->index();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_printers');
    }
};
