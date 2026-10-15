<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Credenciales de servicio para `/api/v1/integrations/*`: SAP, transportistas y
 * emisores de webhooks (P1-AUTH-05).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_clients', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('client_id')->unique();
            // Solo se guarda el hash del secreto (cast 'hashed' en el modelo).
            $table->string('client_secret');
            // Contextos a los que puede acceder; '*' concede todos.
            $table->json('scopes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index('active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_clients');
    }
};
