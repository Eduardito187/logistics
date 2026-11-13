<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora INMUTABLE (solo INSERT) de todo cambio de estado de un caso/vale: quién, cuándo, por qué, con
 * el cálculo completo. Base de la auditoría (§2.1.5). Aditiva e idempotente.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('compensation_case_log')) {
            return;
        }

        Schema::create('compensation_case_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('case_id')->index();
            $table->uuid('voucher_id')->nullable();
            // opened | approved | rejected | escalated | issued | redeemed | expired | revoked | ...
            $table->string('action');
            // Actor como varchar (gotcha: admin.id es bigint).
            $table->string('actor');
            $table->jsonb('payload')->nullable();
            $table->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compensation_case_log');
    }
};
