<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de promesas de entrega: una promesa por envío hacia un canal, con su franja y el estado de la
 * reserva temporal de cupo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promises', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('shipment_ref')->index();
            $table->uuid('zone_id')->index();
            $table->date('slot_date');
            $table->string('time_slot');
            $table->string('status')->index();
            $table->timestampTz('reserved_until')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promises');
    }
};
