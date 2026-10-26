<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Logistics\Outbox\Application\EnqueueOutbox\EnqueueOutboxBatch;

/**
 * Dispatcher del outbox (C-2 de la auditoría de throughput). Reemplaza al relay sincrónico como latido
 * del sistema asíncrono: lo corre el scheduler cada minuto y encola un job por mensaje a las colas
 * always-on (`outbox`/`notifications`), de modo que los workers (numprocs>1) despachen en PARALELO.
 *
 * No hace I/O externo: solo toma el lote (SKIP LOCKED + lease) y encola. Por eso el lote por corrida
 * puede ser grande (config `logistics-outbox.pump.batch`): el techo ya no es el del despacho secuencial.
 */
final class PumpOutboxCommand extends Command
{
    protected $signature = 'logistics:outbox:pump {--limit= : Maximum number of messages to enqueue in this run (defaults to config logistics-outbox.pump.batch)}';

    protected $description = 'Pull a batch of pending outbox messages and enqueue one job per message to the always-on workers';

    public function handle(EnqueueOutboxBatch $pump, Repository $config): int
    {
        $option = $this->option('limit');
        $limit = $option === null ? (int) $config->get('logistics-outbox.pump.batch', 1000) : (int) $option;
        if ($limit < 1) {
            $this->error('--limit must be >= 1');

            return self::FAILURE;
        }

        $result = $pump->handle($limit);

        $this->info(sprintf('Outbox pump finished: %d messages enqueued to workers.', $result->enqueued));

        return self::SUCCESS;
    }
}
