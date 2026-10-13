<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ReservePromise;

use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Contract\SlotReserver;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Promise\Domain\ValueObject\ReservationWindow;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Reserva el cupo de una promesa cotizada: toma el cupo en capacity (vía SlotReserver), fija la ventana
 * de reserva y marca la promesa como Reserved.
 *
 * @implements CommandHandler<ReservePromise>
 */
final class ReservePromiseHandler implements CommandHandler
{
    public function __construct(
        private readonly PromiseRepository $promises,
        private readonly SlotReserver $slotReserver,
        private readonly Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ReservePromise) {
            throw PromiseException::because('ReservePromiseHandler solo maneja ReservePromise');
        }

        $promise = $this->promises->findById(PromiseId::fromString($command->promiseId));
        if ($promise === null) {
            throw PromiseException::because("no existe la promesa {$command->promiseId}");
        }

        // Toma el cupo antes de mutar el agregado: si capacity no tiene cupo, la promesa no se reserva.
        if (!$this->slotReserver->reserve($promise->zoneId(), $promise->slot())) {
            throw PromiseException::because('no se pudo reservar el cupo: capacidad agotada');
        }

        $window = ReservationWindow::minutesFrom($this->clock->now(), $command->ttlMinutes);
        $promise->reserve($window);

        $this->promises->save($promise);

        // TODO(outbox): despachar estos eventos al outbox dentro de la misma transacción al integrar.
        $promise->releaseEvents();
    }
}
