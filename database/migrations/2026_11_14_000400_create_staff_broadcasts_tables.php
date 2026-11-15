<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Avisos INTERNOS al personal de la app (no al cliente): un admin escribe un mensaje para un rol, una tienda
 * o personas puntuales; cada destinatario lo ve en su bandeja y recibe un push. `staff_broadcasts` es el
 * mensaje; `staff_broadcast_recipients`, una fila por destinatario con su estado (leído / push enviado).
 * Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('staff_broadcasts')) {
            Schema::create('staff_broadcasts', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('title', 120);
                $table->string('body', 1000);
                $table->string('link', 500)->nullable();
                // A quién se dirigió (rol / tienda / usuarios), tal como lo pidió quien lo envió.
                $table->jsonb('audience');
                $table->unsignedBigInteger('sent_by')->nullable()->index();
                $table->unsignedInteger('recipient_count')->default(0);
                $table->timestamps();
                $table->index('created_at');
            });
        }

        if (!Schema::hasTable('staff_broadcast_recipients')) {
            Schema::create('staff_broadcast_recipients', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('broadcast_id');
                $table->unsignedBigInteger('user_id');
                $table->timestampTz('read_at')->nullable();
                $table->timestampTz('pushed_at')->nullable();
                $table->timestamps();
                $table->unique(['broadcast_id', 'user_id']);
                $table->index(['user_id', 'read_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_broadcast_recipients');
        Schema::dropIfExists('staff_broadcasts');
    }
};
