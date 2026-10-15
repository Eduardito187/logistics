<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query\ListShipmentProducts;

use Logistics\Shared\Application\Query\Query;

/**
 * Lista los productos de un envío (nombre, cantidad, imagen y precio) para que el notificador arme el
 * detalle de productos del mail y el collage del push. Es la forma pública que este contexto expone
 * para que notifications consuma los productos sin conocer el modelo de dominio.
 */
final readonly class ListShipmentProducts implements Query
{
    public function __construct(
        public string $shipmentId,
    ) {}
}
