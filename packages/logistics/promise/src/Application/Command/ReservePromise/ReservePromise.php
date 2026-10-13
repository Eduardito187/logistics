<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ReservePromise;

use Logistics\Shared\Application\Command\Command;

/**
 * Reserva temporalmente el cupo de una promesa ya cotizada, por una ventana de `ttlMinutes` minutos a
 * partir de ahora.
 */
final readonly class ReservePromise implements Command
{
    public function __construct(
        public string $promiseId,
        public int $ttlMinutes,
    ) {}
}
