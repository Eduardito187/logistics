<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inbound_asns', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Seller dueño del producto (contexto sellers) y almacén central destino (contexto network).
            $table->uuid('seller_id')->index();
            $table->uuid('warehouse_id')->index();
            // announced | scheduled | arrived | receiving | received | closed | cancelled
            $table->string('status')->index();
            // Ventana de cita (nullable hasta agendar): día + franja HH:MM-HH:MM.
            $table->date('appointment_date')->nullable()->index();
            $table->string('appointment_start', 5)->nullable();
            $table->string('appointment_end', 5)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_asns');
    }
};
