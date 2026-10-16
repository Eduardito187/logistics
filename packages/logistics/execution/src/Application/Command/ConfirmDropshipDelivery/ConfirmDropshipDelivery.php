<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ConfirmDropshipDelivery;

use Logistics\Shared\Application\Command\Command;

/**
 * El seller (o el transportista tercero) confirma la ENTREGA al cliente de un envío dropship. Cierra la
 * entrega (terminal) sin prueba de entrega propia de Dismac. Idempotente por idempotencyKey.
 */
final readonly class ConfirmDropshipDelivery implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $occurredAt,
        public string $idempotencyKey,
        public ?string $reference = null,
    ) {}
}
