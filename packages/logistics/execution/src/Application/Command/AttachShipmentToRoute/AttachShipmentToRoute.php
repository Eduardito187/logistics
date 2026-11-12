<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\AttachShipmentToRoute;

use Logistics\Shared\Application\Command\Command;

/**
 * Asigna un envío al camión de reparto desde el andén: lo agrega como parada a la ruta ABIERTA del
 * vehículo (o arma una ruta nueva para hoy si no tiene). Es lo que hace que el pedido aparezca en
 * «Mi ruta de hoy» del chofer de ese camión y, luego, se pueda cerrar con el POD.
 *
 * La validación de que el vehículo existe y está operativo la hace la capa Presentation (lectura
 * cross-context a fleet) antes de despachar; acá el envío ya viene resuelto a su shipmentId.
 */
final readonly class AttachShipmentToRoute implements Command
{
    public function __construct(
        public string $vehicleId,
        public string $shipmentId,
        public ?string $orderReference = null,
        public ?string $customerName = null,
        public ?string $address = null,
    ) {}
}
