<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordStoreDrop;

use Logistics\Shared\Application\Command\Command;

/**
 * El chofer deja en la tienda un pedido que el cliente retira ahí (o un traspaso): cierra SU parada sin
 * prueba de entrega del cliente. El pedido no queda entregado: queda en la tienda, listo para retirar.
 */
final readonly class RecordStoreDrop implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $nodeId,
        public string $occurredAt,
        public ?string $photoPath = null,
    ) {}
}
