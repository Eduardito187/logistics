<?php

declare(strict_types=1);

namespace Logistics\Shared\Infrastructure\Bus;

use Illuminate\Contracts\Container\Container;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Application\HandlerRegistry;

/**
 * CommandBus síncrono sobre el contenedor de Laravel: resuelve el handler del comando y lo ejecuta.
 *
 * Síncrono a propósito: el handler corre dentro del request/job actual y, con él, su transacción y el
 * registro de eventos en el outbox. Nada de I/O externo acá; eso sale después por el outbox.
 */
final readonly class LaravelCommandBus implements CommandBus
{
    public function __construct(
        private Container $container,
        private HandlerRegistry $registry,
    ) {}

    public function dispatch(Command $command): void
    {
        $handlerClass = $this->registry->handlerFor($command);
        /** @var CommandHandler $handler */
        $handler = $this->container->make($handlerClass);
        $handler->handle($command);
    }
}
