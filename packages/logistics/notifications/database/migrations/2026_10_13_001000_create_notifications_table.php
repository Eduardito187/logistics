<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de notificaciones al cliente: una notificación por hecho de un envío, que recorre la cascada
 * de canales del ADR 006. La política (canales en orden) y los intentos por canal se guardan como
 * jsonb; current_channel marca la posición actual en la cascada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->index();
            $table->string('message_type');
            $table->string('status')->index();
            $table->string('current_channel');
            $table->jsonb('policy');
            $table->jsonb('attempts');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
