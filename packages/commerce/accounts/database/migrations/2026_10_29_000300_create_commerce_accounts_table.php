<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_accounts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // person | company | seller_person
            $table->string('type')->index();
            // Usuario de `access` (autoridad de identidad/authN); se referencia por UUID (ADR 018).
            $table->uuid('user_id')->index();
            $table->string('display_name');
            // NIT para empresas (obligatorio en type=company). No es UUID.
            $table->string('tax_id')->nullable();
            // active | suspended
            $table->string('status')->index();
            // Direcciones como JSONB (value objects del agregado, no entidades consultables aparte).
            $table->jsonb('addresses')->default('[]');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_accounts');
    }
};
