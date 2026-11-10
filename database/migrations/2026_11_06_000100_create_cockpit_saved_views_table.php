<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vistas guardadas por usuario para los listados del admin (capa CRM). Cada fila es un "segmento +
 * filtros" que un usuario de oficina guarda con un nombre y reutiliza con un clic: persiste la pestaña
 * activa y el estado de los filtros de tabla de UN recurso. Son preferencias personales (no datos de
 * negocio): privadas por usuario y recurso.
 *
 * Aditiva e idempotente (no recrea si ya existe).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cockpit_saved_views')) {
            return;
        }

        Schema::create('cockpit_saved_views', function (Blueprint $table): void {
            $table->id();
            // Dueño de la vista. Nullable + nullOnDelete: si se borra el usuario, la vista queda huérfana
            // sin romper la FK (se limpia aparte); en la práctica siempre se consulta por user_id concreto.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            // Clase del Resource de Filament al que pertenece la vista (ámbito del guardado).
            $table->string('resource');
            $table->string('name');
            // Estado capturado: { tab: ?string, filters: array }.
            $table->json('payload');
            $table->timestamps();

            $table->index(['user_id', 'resource']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cockpit_saved_views');
    }
};
