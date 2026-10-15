<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de suscripciones Web Push de la página pública de rastreo (K-04).
 *
 * Guarda la suscripción estándar que entrega el navegador (endpoint + claves p256dh/auth) junto al
 * envío al que pertenece. El endpoint es la clave natural (único): re-suscribir el mismo navegador
 * actualiza la fila en vez de duplicarla. Sin PII: el envío se referencia por id, el token es el scope.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracking_push_subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->index();
            $table->string('token');
            $table->string('endpoint', 1024)->unique();
            $table->string('p256dh');
            $table->string('auth');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_push_subscriptions');
    }
};
