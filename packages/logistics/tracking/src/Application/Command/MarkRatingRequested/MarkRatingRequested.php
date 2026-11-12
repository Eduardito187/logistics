<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Command\MarkRatingRequested;

use Logistics\Shared\Application\Command\Command;

/**
 * Marca que ya se pidió la calificación del envío (se envió la invitación), para que el cron horario no lo
 * vuelva a listar. `requestedAt` (ISO 8601, opcional) es el momento del envío; sin valor se usa el reloj.
 * Idempotente: si ya estaba marcado no cambia nada.
 */
final readonly class MarkRatingRequested implements Command
{
    public function __construct(
        public string $shipmentId,
        public ?string $requestedAt = null,
    ) {}
}
