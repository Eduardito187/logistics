<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Outbox\Application\RelayOutbox\RelayOutboxBatch;

/**
 * Despacha un lote del outbox. Lo corre el worker/scheduler (p. ej. cada minuto) o se invoca a mano.
 *
 * No hace I/O externo por sí mismo: delega en RelayOutboxBatch, que invoca al MessageDispatcher ligado
 * en el contenedor. Imprime el resumen de la corrida para poder monitorearlo.
 */
final class RelayOutboxCommand extends Command
{
    protected $signature = 'logistics:outbox:relay {--limit=100 : Maximum number of messages to dispatch in this run}';

    protected $description = 'Dispatch a batch of pending outbox messages to their external destinations';

    public function handle(RelayOutboxBatch $relay): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1) {
            $this->error('--limit must be >= 1');

            return self::FAILURE;
        }

        $result = $relay->handle($limit);

        $this->info(sprintf(
            'Outbox relay finished: %d pulled, %d dispatched, %d failed, %d dead-lettered.',
            $result->pulled,
            $result->dispatched,
            $result->failed,
            $result->deadLettered,
        ));

        return self::SUCCESS;
    }
}
