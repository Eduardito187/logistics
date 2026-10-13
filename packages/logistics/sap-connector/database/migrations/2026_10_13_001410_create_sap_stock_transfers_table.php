<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Traspasos de stock hacia SAP, encolados al recibir en un nodo (ADR 002).
 *
 * El índice único sobre `event_id` da la idempotencia: una recepción (event_id) encola un solo
 * traspaso. El índice sobre `status` sirve al pull del worker de despacho y al panel de reconciliación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sap_stock_transfers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('event_id')->unique();
            $table->string('shipment_ref')->index();
            $table->string('material');
            $table->string('center');
            $table->string('warehouse');
            $table->unsignedInteger('quantity');
            $table->string('status')->index();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sap_stock_transfers');
    }
};
