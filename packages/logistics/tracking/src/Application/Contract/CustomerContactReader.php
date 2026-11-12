<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\Contract;

use Logistics\Tracking\Application\ReadModel\CustomerContact;

/**
 * Puerto de lectura CROSS-CONTEXT: contacto del cliente de un envío. El evento de entrega de execution no
 * trae el email, y el seguimiento no debe acoplarse al dominio de shipments, así que la infraestructura lo
 * lee del lado lectura (tabla `shipments`) en solo lectura. Null si el envío no existe.
 */
interface CustomerContactReader
{
    public function forShipment(string $shipmentId): ?CustomerContact;
}
