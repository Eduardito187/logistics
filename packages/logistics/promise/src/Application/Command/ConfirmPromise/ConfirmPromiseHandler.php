<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ConfirmPromise;

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
 * @implements CommandHandler<ConfirmPromise>
 */
final class ConfirmPromiseHandler implements CommandHandler
{
    public function __construct(
        private readonly PromiseRepository $promises,
        private readonly Clock $clock,
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

        // TODO(outbox): despachar estos eventos al outbox dentro de la misma transacción al integrar.
        $promise->releaseEvents();
    }
}
