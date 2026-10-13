<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ExpireReservation;

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
 * @implements CommandHandler<ExpireReservation>
 */
final class ExpireReservationHandler implements CommandHandler
{
    public function __construct(
        private readonly PromiseRepository $promises,
        private readonly SlotReserver $slotReserver,
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

        // TODO(outbox): despachar estos eventos al outbox dentro de la misma transacción al integrar.
        $promise->releaseEvents();
    }
}
