<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('access_audit_log', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Autor de la acción: id de la cuenta administradora. Nulo = acción del propio sistema.
            $table->string('actor_id')->nullable()->index();
            // Etiqueta legible del autor (nombre o email) congelada al momento del hecho.
            $table->string('actor_label');
            // Acción auditada en formato "sujeto.verbo" (p. ej. "user.suspended"). Indexada para filtrar.
            $table->string('action')->index();
            // Tipo de agregado sobre el que se actuó (p. ej. "access.user", "access.role").
            $table->string('subject_type')->index();
            // Id del sujeto afectado. Nulo si la acción no apunta a una entidad concreta.
            $table->string('subject_id')->nullable()->index();
            // Metadatos del hecho (primitivos): roles agregados/quitados, nombre nuevo, alcance, etc.
            $table->jsonb('context');
            // IP de origen de la petición (soporta IPv6). Nula si no se pudo determinar.
            $table->string('ip_address', 45)->nullable();
            // Momento exacto del hecho, con la hora del reloj del dominio. Indexado para ordenar/filtrar.
            $table->timestamp('occurred_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('access_audit_log');
    }
};
