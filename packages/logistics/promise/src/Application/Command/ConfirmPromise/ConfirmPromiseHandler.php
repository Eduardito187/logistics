<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ConfirmPromise;

use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Confirma una promesa reservada. El dominio verifica contra el reloj que la ventana no venció; si
 * venció, lanza y la promesa queda para expirar.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<ConfirmPromise>
 */
final class ConfirmPromiseHandler implements CommandHandler
{
    public function __construct(
        private readonly PromiseRepository $promises,
        private readonly Clock $clock,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof ConfirmPromise) {
            throw PromiseException::because('ConfirmPromiseHandler solo maneja ConfirmPromise');
        }

        $promise = $this->promises->findById(PromiseId::fromString($command->promiseId));
        if ($promise === null) {
            throw PromiseException::because("no existe la promesa {$command->promiseId}");
        }

        $promise->confirm($this->clock->now());

        $this->promises->save($promise);

        $this->recordEvents->record($promise->releaseEvents(), 'promise.promise');
    }
}
