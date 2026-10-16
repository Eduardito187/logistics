<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dimensión de propiedad de los traspasos de stock hacia SAP (ADR 017, roadmap marketplace §6.4).
 *
 * Es aditiva: `transfer_kind` nace con default 'owned' (stock propio de Dismac), así que todos los
 * traspasos existentes y el flujo retail actual quedan intactos. `owner_party_id` (seller) solo aplica a
 * la consignación. La regla dura (ver SapMessageDispatcher) es que la consignación nunca se postea a SAP
 * como material propio de Dismac.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sap_stock_transfers', function (Blueprint $table): void {
            // Default 'owned' = comportamiento retail histórico; el índice sirve al filtro de propiedad
            // del panel de reconciliación.
            $table->string('transfer_kind')->default('owned')->index()->after('status');
            // Seller dueño del stock en una consignación; null para el stock propio de Dismac.
            $table->uuid('owner_party_id')->nullable()->index()->after('transfer_kind');
        });
    }

    public function down(): void
    {
        Schema::table('sap_stock_transfers', function (Blueprint $table): void {
            $table->dropColumn(['transfer_kind', 'owner_party_id']);
        });
    }
};
