<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ReleasePromiseReservation;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Contract\SlotReserver;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Libera la reserva de una promesa por cancelación de la orden y devuelve su cupo a capacity (vía
 * SlotReserver), igual que ExpireReservation pero válido también desde Confirmed (cancelación post-pago).
 *
 * El cupo solo se libera si la promesa lo mantenía tomado (Reserved o Confirmed) ANTES de la liberación;
 * así una reentrega del comando sobre una promesa ya terminal no descuenta capacidad de más (release es
 * además idempotente y con piso en 0 del lado de capacity). Si la promesa no existe, es no-op: pudo no
 * haberse reservado cupo para esa orden.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<ReleasePromiseReservation>
 */
final class ReleasePromiseReservationHandler implements CommandHandler
{
    public function __construct(
        private readonly PromiseRepository $promises,
        private readonly SlotReserver $slotReserver,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ReleasePromiseReservation) {
            throw PromiseException::because('ReleasePromiseReservationHandler solo maneja ReleasePromiseReservation');
        }

        $promise = $this->promises->findById(PromiseId::fromString($command->promiseId));
        if ($promise === null) {
            return;
        }

        $wasHolding = $promise->holdsCapacity();

        $promise->release();

        if ($wasHolding) {
            $this->slotReserver->release($promise->zoneId(), $promise->slot());
        }

        $this->promises->save($promise);

        $this->recordEvents->record($promise->releaseEvents(), 'promise.promise');
    }
}
