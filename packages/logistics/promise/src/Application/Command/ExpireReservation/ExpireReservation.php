<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\ExpireReservation;

use Logistics\Shared\Application\Command\Command;

/**
 * Expira la reserva de una promesa cuya ventana venció y libera su cupo. Lo dispara el barrido de
 * reservas vencidas (scheduler) o el propio canal al abandonar.
 */
final readonly class ExpireReservation implements Command
{
    public function __construct(
        public string $promiseId,
    ) {}
}
