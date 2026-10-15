<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('labels_emitted', function (Blueprint $table): void {
            // Revocación por reimpresión (ADR 004): la fila no se borra, se marca inválida.
            $table->timestampTz('revoked_at')->nullable()->after('created_at');
            $table->string('revoked_reason')->nullable()->after('revoked_at');
            // Acelera el filtro "vigentes de este pedido" (revoked_at is null).
            $table->index(['order_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::table('labels_emitted', function (Blueprint $table): void {
            $table->dropIndex(['order_id', 'revoked_at']);
            $table->dropColumn(['revoked_at', 'revoked_reason']);
        });
    }
};
