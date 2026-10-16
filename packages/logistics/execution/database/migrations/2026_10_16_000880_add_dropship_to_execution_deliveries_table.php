<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensión de fulfillment en la entrega (ADITIVO, ver docs/marketplace-dropship-roadmap.md §8). Todo lo
 * existente queda `owned` por defecto: ningún flujo OWNED/FBD cambia.
 *
 * - `fulfillment_mode`          (string, NOT NULL, default 'owned'): owned | fbd | dropship.
 * - `dropship_handling`         (string, NULLABLE): track_only | third_party_carrier | last_mile. Solo en dropship.
 * - `dispatch_confirmation_key` (string, NULLABLE): idempotencia de la confirmación de despacho del seller.
 * - `delivery_confirmation_key` (string, NULLABLE): idempotencia de la confirmación de entrega del seller.
 *
 * Índice por `fulfillment_mode` para segmentar reportería a escala. Idempotente (hasColumn).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('execution_deliveries', function (Blueprint $table): void {
            if (!Schema::hasColumn('execution_deliveries', 'fulfillment_mode')) {
                $table->string('fulfillment_mode')->default('owned')->index()->after('failure_reason');
            }

            if (!Schema::hasColumn('execution_deliveries', 'dropship_handling')) {
                $table->string('dropship_handling')->nullable()->after('fulfillment_mode');
            }

            if (!Schema::hasColumn('execution_deliveries', 'dispatch_confirmation_key')) {
                $table->string('dispatch_confirmation_key')->nullable()->after('dropship_handling');
            }

            if (!Schema::hasColumn('execution_deliveries', 'delivery_confirmation_key')) {
                $table->string('delivery_confirmation_key')->nullable()->after('dispatch_confirmation_key');
            }
        });
    }

    public function down(): void
    {
        Schema::table('execution_deliveries', function (Blueprint $table): void {
            foreach (['fulfillment_mode', 'dropship_handling', 'dispatch_confirmation_key', 'delivery_confirmation_key'] as $column) {
                if (Schema::hasColumn('execution_deliveries', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
