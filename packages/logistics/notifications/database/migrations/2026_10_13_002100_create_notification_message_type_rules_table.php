<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reglas de cascada configurables por tipo de mensaje (P1-NOT-01). Una fila por tipo (PK message_type);
 * los canales se guardan como jsonb en orden de cascada y escalate_when es la condición de salto. Si un
 * tipo no tiene fila, la aplicación usa la cascada por defecto del ADR 006.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_message_type_rules', function (Blueprint $table): void {
            $table->string('message_type')->primary();
            $table->jsonb('channels');
            $table->string('escalate_when');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_message_type_rules');
    }
};
