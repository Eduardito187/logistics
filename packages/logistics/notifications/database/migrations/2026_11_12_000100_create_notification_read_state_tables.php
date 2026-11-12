<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estado de lectura POR USUARIO de las notificaciones que ve la app interna (pedido #9 de la auditoría
 * de la app). Aditiva e idempotente: cada tabla se crea solo si no existe.
 *
 *  - notification_reads: una fila por (usuario, notificación) marcada como leída de a una. La PK
 *    compuesta (user_id, notification_id) hace el marcado idempotente (INSERT ... ON CONFLICT DO NOTHING
 *    conserva el primer read_at) y resuelve el NOT EXISTS del filtro de no leídas como búsqueda puntual.
 *  - notification_read_marks: "marca de agua" por usuario de «marcar todas como leídas». En vez de
 *    insertar una fila por notificación (millones), guarda hasta qué created_at quedó todo leído; una
 *    notificación está leída si created_at <= read_up_to o si tiene fila propia en notification_reads.
 *
 * user_id es varchar (no uuid ni bigint): el id del usuario autenticado viaja como string y la tabla de
 * usuarios usa ids enteros (mismo criterio que las columnas de actor del resto de la plataforma).
 * Sin FK a notifications/users: el contexto queda extraíble a un servicio propio (ADR 001/008).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('notification_reads')) {
            Schema::create('notification_reads', function (Blueprint $table): void {
                $table->string('user_id', 64);
                $table->uuid('notification_id');
                $table->timestamp('read_at');
                $table->primary(['user_id', 'notification_id'], 'notification_reads_pkey');
            });
        }

        if (!Schema::hasTable('notification_read_marks')) {
            Schema::create('notification_read_marks', function (Blueprint $table): void {
                $table->string('user_id', 64)->primary();
                $table->timestamp('read_up_to');
                $table->timestamp('marked_at');
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_read_marks');
        Schema::dropIfExists('notification_reads');
    }
};
