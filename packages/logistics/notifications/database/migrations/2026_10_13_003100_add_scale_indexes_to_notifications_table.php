<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices de escala para las notificaciones (frente E — escala). Aditivos: solo aceleran lectura.
 *
 *  - (shipment_id, message_type): `existsForShipmentAndType` decide la idempotencia antes de crear una
 *    notificación (una por hecho del envío). Es un acceso caliente de escritura/lectura; el compuesto
 *    lo resuelve como búsqueda puntual en vez de filtrar sobre el índice simple de shipment_id.
 *  - (shipment_id, created_at): `findByShipment` lista el historial del envío ordenado por fecha
 *    descendente; el compuesto sirve filtro + orden de una pasada.
 *  - created_at: `latest()` muestra las últimas N notificaciones del panel, ordenadas por fecha.
 *
 * `shipment_id` y `status` ya están indexados desde la creación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->index(['shipment_id', 'message_type'], 'notifications_shipment_message_type_index');
            $table->index(['shipment_id', 'created_at'], 'notifications_shipment_created_at_index');
            $table->index('created_at', 'notifications_created_at_index');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table): void {
            $table->dropIndex('notifications_shipment_message_type_index');
            $table->dropIndex('notifications_shipment_created_at_index');
            $table->dropIndex('notifications_created_at_index');
        });
    }
};
