<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabla de versiones de configuración: historial inmutable de reglas (coberturas, cupos, cortes, tarifas).
 *
 * Cada fila es una versión de una clave; nunca se actualiza ni se borra, solo se inserta. El índice
 * único (key, version) garantiza versiones consecutivas sin huecos ni duplicados ante concurrencia.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings_versions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('key')->index();
            $table->unsignedInteger('version');
            $table->jsonb('value');
            $table->timestampTz('effective_from')->index();
            $table->timestampTz('created_at');
            $table->string('created_by')->nullable();

            $table->unique(['key', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings_versions');
    }
};
