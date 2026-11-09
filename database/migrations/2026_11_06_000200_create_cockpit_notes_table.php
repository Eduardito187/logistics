<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notas y seguimientos (actividad CRM) que la oficina adjunta a una entidad del admin: un cliente, un
 * pedido, un envío, un vehículo, etc. Cada fila es una anotación con autor y fecha, y opcionalmente una
 * TAREA con vencimiento y marca de hecho. Es la capa de "actividad humana" del CRM: anotar, recordar,
 * dar seguimiento; no es dato de negocio ni toca el dominio.
 *
 * Polimórfica por (subject_type, subject_id) en texto, para colgar de cualquier entidad sin acoplar FKs
 * entre contextos (subject_id puede ser un UUID de agregado o una clave legible como el nombre de cliente).
 *
 * Aditiva e idempotente (no recrea si ya existe).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cockpit_notes')) {
            return;
        }

        Schema::create('cockpit_notes', function (Blueprint $table): void {
            $table->id();
            // Autor (usuario de oficina). Nullable + nullOnDelete: si se borra el usuario, la nota queda
            // pero conserva su etiqueta de autor (author_label) para seguir siendo legible.
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            // Nombre del autor congelado al crear la nota (para mostrar sin join aunque el usuario cambie/borre).
            $table->string('author_label')->nullable();
            // Entidad a la que se adjunta: tipo lógico ('customer', 'order', 'shipment', 'vehicle', 'driver'…)
            // e identificador en texto (UUID de agregado o clave legible como el nombre del cliente).
            $table->string('subject_type');
            $table->string('subject_id');
            // Cuerpo de la nota.
            $table->text('body');
            // 'note' = anotación simple; 'task' = seguimiento con vencimiento y marca de hecho.
            $table->string('kind')->default('note');
            // Solo para tareas: vencimiento y momento en que se marcó hecha (null = pendiente).
            $table->date('due_on')->nullable();
            $table->timestamp('done_at')->nullable();
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cockpit_notes');
    }
};
