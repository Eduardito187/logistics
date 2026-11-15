<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordStoreDrop;

use Logistics\Shared\Application\Command\Command;

/**
 * El chofer deja en la tienda un pedido que el cliente retira ahí (o un traspaso): cierra SU parada sin
 * prueba de entrega del cliente. El pedido no queda entregado: queda en la tienda, listo para retirar.
 *
 * `missingUnits` (opcional) son los productos que NO pudo dejar, cada uno con su motivo (value de
 * MissingUnitReason: not_arrived | damaged | wrong_store | other). La tienda ya no concilia una recepción:
 * el faltante lo informa el chofer acá.
 */
final readonly class RecordStoreDrop implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $nodeId,
        public string $occurredAt,
        public ?string $photoPath = null,
        /** @var list<array{unit_id: string, reason: string}> */
        public array $missingUnits = [],
    ) {}
}
