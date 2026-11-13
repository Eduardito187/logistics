<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de VALES (vouchers): el crédito de un solo uso, nominativo, con código único y vencimiento.
 * Aditiva e idempotente. Incluye un índice PARCIAL para el barrido de vencimiento (solo los emitidos).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('compensation_vouchers')) {
            Schema::create('compensation_vouchers', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->uuid('case_id')->unique();
                // Código único enmascarable (DT-XXXX-XXXX): el índice único evita colisiones.
                $table->string('code')->unique();
                $table->bigInteger('amount_cents');
                $table->string('currency', 3)->default('BOB');

                // Titular: hash para búsquedas sin PII en claro + email para el envío.
                $table->string('holder_email_hash')->nullable()->index();
                $table->string('holder_email')->nullable();
                $table->timestamp('expires_at')->index();

                // issued | redeemed | expired | revoked
                $table->string('status')->index();

                // Sincronización con Magento (ADR: fire-and-forget por outbox).
                $table->string('magento_rule_id')->nullable();
                // pending | synced | failed
                $table->string('magento_sync_status')->default('pending');

                // Hold temporal para POS (reserva por ttl durante una venta).
                $table->timestamp('held_until')->nullable();
                $table->string('held_by_channel')->nullable();

                // Canje (un solo uso): canal, referencia de venta (idempotencia), momento.
                $table->timestamp('redeemed_at')->nullable();
                $table->string('redeemed_channel')->nullable();
                $table->string('redeemed_sale_ref')->nullable()->unique();

                $table->timestamps();
            });
        }

        // Índice parcial: el barrido de vencimiento solo mira los vales vigentes (status = 'issued').
        // Idempotente por IF NOT EXISTS. Patrón del índice parcial del outbox (memoria perf-scale).
        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                "CREATE INDEX IF NOT EXISTS compensation_vouchers_expiring_idx "
                ."ON compensation_vouchers (expires_at) WHERE status = 'issued'",
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS compensation_vouchers_expiring_idx');
        }

        Schema::dropIfExists('compensation_vouchers');
    }
};
