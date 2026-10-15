<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega a cada parada del plan la FOTO legible del destino: referencia del pedido, cliente y dirección.
 *
 * Son datos denormalizados que aporta quien conoce el envío al asignarlo. Viajan con la parada para que
 * el plan —y la ruta del chofer que se arma a partir de él (contexto Ejecución)— se muestren y se
 * entreguen sin volver a consultar el contexto de envíos. Todos son opcionales (pueden ser nulos).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('planning_plan_stops', function (Blueprint $table): void {
            $table->string('order_reference')->nullable()->after('shipment_id');
            $table->string('customer_name')->nullable()->after('order_reference');
            $table->string('address')->nullable()->after('customer_name');
        });
    }

    public function down(): void
    {
        Schema::table('planning_plan_stops', function (Blueprint $table): void {
            $table->dropColumn(['order_reference', 'customer_name', 'address']);
        });
    }
};
