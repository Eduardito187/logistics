<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ExpireReservation;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Contract\SlotReserver;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Expira la reserva de una promesa y libera su cupo en capacity (vía SlotReserver). El agregado solo
 * permite expirar desde Reserved; liberar el cupo es idempotente.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<ExpireReservation>
 */
final class ExpireReservationHandler implements CommandHandler
{
    public function __construct(
        private readonly PromiseRepository $promises,
        private readonly SlotReserver $slotReserver,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ExpireReservation) {
            throw PromiseException::because('ExpireReservationHandler solo maneja ExpireReservation');
        }

        $promise = $this->promises->findById(PromiseId::fromString($command->promiseId));
        if ($promise === null) {
            throw PromiseException::because("no existe la promesa {$command->promiseId}");
        }

        $promise->expire();
        $this->slotReserver->release($promise->zoneId(), $promise->slot());

        $this->promises->save($promise);

        $this->recordEvents->record($promise->releaseEvents(), 'promise.promise');
    }
}
