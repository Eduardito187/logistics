<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agrega los datos básicos de contacto del cliente a la cabecera del pedido: documento de identidad
 * (CI/NIT), teléfono y correo. Son opcionales (nullable) porque las fuentes mínimas no siempre los
 * traen; cuando llegan, pueblan la ficha del cliente en el panel y alimentan al notificador.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->string('customer_document_id')->nullable()->after('customer_name');
            $table->string('customer_phone')->nullable()->after('customer_document_id');
            $table->string('customer_email')->nullable()->after('customer_phone');
        });
    }

    public function down(): void
    {
        Schema::table('shipments', function (Blueprint $table): void {
            $table->dropColumn(['customer_document_id', 'customer_phone', 'customer_email']);
        });
    }
};
