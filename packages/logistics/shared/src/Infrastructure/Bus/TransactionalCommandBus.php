<?php

declare(strict_types=1);

namespace Logistics\Shared\Infrastructure\Bus;

use Illuminate\Database\ConnectionInterface;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Decorador transaccional del CommandBus.
 *
 * Envuelve cada dispatch() en una transacción de base de datos, de modo que el save del repositorio
 * y el append de los eventos al outbox (que el handler hace vía RecordDomainEvents) sean atómicos:
 * o se confirman juntos o no se confirma nada (ADR 002 / 008). Si el handler lanza, el rollback
 * deshace tanto el cambio de negocio como los mensajes del outbox.
 *
 * Vive en Infrastructure a propósito: la capa Application es PHP puro y no conoce Illuminate. El
 * límite transaccional es un detalle de infraestructura, así que no entra en el handler.
 */
final readonly class TransactionalCommandBus implements CommandBus
{
    public function __construct(
        private CommandBus $inner,
        private ConnectionInterface $connection,
    ) {}

    public function dispatch(Command $command): void
    {
        $this->connection->transaction(function () use ($command): void {
            $this->inner->dispatch($command);
        });
    }
}
