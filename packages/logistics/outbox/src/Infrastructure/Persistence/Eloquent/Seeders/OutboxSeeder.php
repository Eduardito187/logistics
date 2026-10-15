<?php

declare(strict_types=1);

namespace Logistics\Outbox\Infrastructure\Persistence\Eloquent\Seeders;

use Illuminate\Database\Seeder;
use Logistics\Outbox\Infrastructure\Persistence\Eloquent\OutboxMessageRecord;

/**
 * Seeder de demo del outbox: siembra mensajes en los cuatro estados para que el panel y el tablero de
 * operación tengan datos realistas (pendientes, despachados, fallidos a la espera de reintento y
 * dead-letter). Lo invoca el DatabaseSeeder raíz (lo wirea el integrador); no corre sobre la base de
 * tests.
 */
final class OutboxSeeder extends Seeder
{
    /**
     * Error fijo del mensaje dead-letter de demo: marcador estable para la idempotencia. La tabla del
     * outbox NO es exclusiva de este seeder (CQRS escribe eventos reales ahí), así que no sirve contar
     * filas; este last_error solo lo inserta este seeder.
     */
    private const DEAD_LETTER_MARKER = 'SAP rejected the transfer: material 000000000040012345 is blocked';

    public function run(): void
    {
        // Idempotente: si los mensajes de demo ya están sembrados, no se vuelve a sembrar (un segundo
        // run duplicaría el histórico). Se detecta por el error fijo del dead-letter, que solo este
        // seeder inserta (ver DEAD_LETTER_MARKER).
        if (OutboxMessageRecord::query()->where('last_error', self::DEAD_LETTER_MARKER)->exists()) {
            return;
        }

        // Cola viva: pendientes de despacho (el relay los tomará en su próxima corrida).
        OutboxMessageRecord::factory()->count(12)->create();

        // Ya despachados con éxito: el grueso del histórico.
        OutboxMessageRecord::factory()->count(40)->dispatched()->create();

        // Fallidos esperando su próximo intento (backoff): foco del monitoreo.
        OutboxMessageRecord::factory()->count(4)->failed()->create();

        // Dead-letter: agotaron reintentos, requieren intervención manual desde el panel.
        OutboxMessageRecord::factory()->count(2)->deadLettered()->create();
    }
}
