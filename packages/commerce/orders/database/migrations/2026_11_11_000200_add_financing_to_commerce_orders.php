<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Financiamiento de la orden (regla de minicuotas/crédito): el paso a «procesando» depende de si es contado
 * (pago capturado) o crédito con/sin inicial (firma de contrato [+ pago del inicial]). Migración ADITIVA e
 * idempotente (ver memoria no-destructive-db-ops): default 'cash' para no alterar las órdenes existentes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('commerce_orders', 'financing')) {
            return;
        }

        Schema::table('commerce_orders', function (Blueprint $table): void {
            $table->string('financing')->default('cash')->index()->after('shipment_requested');
            $table->boolean('contract_signed')->default(false)->after('financing');
            $table->boolean('initial_paid')->default(false)->after('contract_signed');
        });
    }

    public function down(): void
    {
        if (!Schema::hasColumn('commerce_orders', 'financing')) {
            return;
        }

        Schema::table('commerce_orders', function (Blueprint $table): void {
            $table->dropColumn(['financing', 'contract_signed', 'initial_paid']);
        });
    }
};
