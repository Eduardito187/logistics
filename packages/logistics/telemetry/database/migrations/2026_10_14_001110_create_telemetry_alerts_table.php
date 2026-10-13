<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('telemetry_alerts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Vehículo al que corresponde la alerta (UUID opaco del contexto fleet).
            $table->string('vehicle_id')->index();
            $table->string('driver_id')->nullable();
            // Tipo de mal uso detectado (ver AlertType).
            $table->string('type')->index();
            // Severidad (info/warning/critical): ordena la atención de la operación.
            $table->string('severity')->index();
            // Punto geográfico donde se detectó la alerta (opcional). decimal(10,7), sin PostGIS.
            $table->decimal('lat', 10, 7)->nullable();
            $table->decimal('lng', 10, 7)->nullable();
            // Contexto de la detección (velocidad vs límite, geocerca violada, segundos ociosos, etc.).
            $table->jsonb('detail');
            // Momento en que ocurrió (lo pone la telemetría, no el servidor).
            $table->timestampTz('occurred_at')->index();
            // Reconocimiento: cuándo y quién la tomó. Nulo mientras no se reconozca.
            $table->timestampTz('acknowledged_at')->nullable();
            $table->string('acknowledged_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('telemetry_alerts');
    }
};
