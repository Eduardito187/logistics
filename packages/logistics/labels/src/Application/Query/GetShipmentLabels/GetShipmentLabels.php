<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Query\GetShipmentLabels;

use Logistics\Shared\Application\Query\Query;

/**
 * Pide las etiquetas ya emitidas de un pedido (para reimprimir, auditar o enviar a la app).
 */
final readonly class GetShipmentLabels implements Query
{
    public function __construct(
        public string $orderNumber,
    ) {}
}
