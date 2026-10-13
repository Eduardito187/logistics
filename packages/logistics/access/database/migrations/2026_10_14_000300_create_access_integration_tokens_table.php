<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_integration_tokens', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name');
            // Prefijo público del token: indexado y único, ubica la fila sin revelar el secreto.
            $table->string('token_prefix')->unique();
            // Hash del secreto de alta entropía (p. ej. SHA-256). El secreto en claro no se persiste.
            $table->string('token_hash');
            // Permisos concedidos (claves "grupo.accion" o ["*"]).
            $table->jsonb('permission_keys');
            $table->string('status')->index();
            // Vencimiento opcional: nulo = sin expiración.
            $table->timestamp('expires_at')->nullable();
            // Último uso del token para autenticar; lo actualiza el comando RecordTokenUsage.
            $table->timestamp('last_used_at')->nullable();
            // Identificador de quién emitió el token (opcional, p. ej. id de usuario administrador).
            $table->string('created_by')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_integration_tokens');
    }
};
