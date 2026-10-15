<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ExpireDueReservations;

use Logistics\Promise\Application\Command\ExpireReservation\ExpireReservation;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Handler del barrido en lote de reservas vencidas. Busca las promesas reservadas cuya ventana ya venció
 * y despacha un ExpireReservation por cada una a través del CommandBus.
 *
 * Cada expiración corre en su propia transacción (la que abre el decorador transaccional del CommandBus),
 * así una promesa que falle —por una carrera que ya la confirmó o expiró— no tumba el resto del barrido.
 * El handler no se despacha por el bus: lo invoca directo el comando de consola, por eso no abre una
 * transacción que envuelva todo el lote. Expone los contadores de la última corrida para monitoreo.
 *
 * @implements CommandHandler<ExpireDueReservations>
 */
final class ExpireDueReservationsHandler implements CommandHandler
{
    private int $lastExpired = 0;

    private int $lastFailed = 0;

    public function __construct(
        private readonly PromiseRepository $promises,
        private readonly Clock $clock,
        private readonly CommandBus $commandBus,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ExpireDueReservations) {
            throw PromiseException::because('ExpireDueReservationsHandler solo maneja ExpireDueReservations');
        }

        $this->lastExpired = 0;
        $this->lastFailed = 0;

        foreach ($this->promises->reservedExpiredAt($this->clock->now()) as $id) {
            try {
                $this->commandBus->dispatch(new ExpireReservation($id->toString()));
                $this->lastExpired++;
            } catch (PromiseException) {
                // Carrera: la promesa se confirmó o expiró entre la lectura y el dispatch. No es un error
                // del barrido; se cuenta como fallida y se sigue con las demás.
                $this->lastFailed++;
            }
        }
    }

    /**
     * Reservas expiradas en la última corrida.
     */
    public function lastExpired(): int
    {
        return $this->lastExpired;
    }

    /**
     * Reservas que no se pudieron expirar en la última corrida (carreras de estado).
     */
    public function lastFailed(): int
    {
        return $this->lastFailed;
    }
}
