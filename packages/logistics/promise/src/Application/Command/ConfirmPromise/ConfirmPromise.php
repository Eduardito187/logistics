<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ConfirmPromise;

use Logistics\Shared\Application\Command\Command;

/**
 * Confirma una promesa reservada, siempre que la ventana de reserva no haya vencido.
 */
final readonly class ConfirmPromise implements Command
{
    public function __construct(
        public string $promiseId,
    ) {}
}
