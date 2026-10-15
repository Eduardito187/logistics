<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Promise\Application\Command\ExpireDueReservations\ExpireDueReservations;
use Logistics\Promise\Application\Command\ExpireDueReservations\ExpireDueReservationsHandler;

/**
 * Worker del barrido de reservas de promesa vencidas: expira las reservas cuya ventana caducó y libera su
 * cupo en capacity. Lo corre el scheduler (cada minuto) o se invoca a mano. No hace I/O por sí mismo:
 * delega en el handler, que despacha un ExpireReservation por promesa vía el CommandBus. Imprime el
 * resumen de la corrida para poder monitorearlo.
 */
final class ExpireReservationsCommand extends Command
{
    protected $signature = 'logistics:promise:expire-reservations';

    protected $description = 'Expire due promise slot reservations and release their held capacity';

    public function handle(ExpireDueReservationsHandler $handler): int
    {
        $handler->handle(new ExpireDueReservations);

        $this->info(sprintf(
            'Promise reservation sweep finished: %d expired, %d failed.',
            $handler->lastExpired(),
            $handler->lastFailed(),
        ));

        return self::SUCCESS;
    }
}
