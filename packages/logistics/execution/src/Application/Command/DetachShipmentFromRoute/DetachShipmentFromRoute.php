<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\DetachShipmentFromRoute;

use Logistics\Shared\Application\Command\Command;

/**
 * Saca un envío de la ruta ABIERTA de un vehículo porque se reasignó a otro camión. Lo dispara la
 * asignación de vehículo (POST /app/orders/{n}/vehicle) sobre el camión VIEJO, para que su hoja de ruta
 * quede consistente con la entrega (que ya se movió). Tolerante/idempotente: si el vehículo no tiene ruta
 * abierta, el envío no está en ella, o su parada ya fue atendida, no hace nada.
 */
final readonly class DetachShipmentFromRoute implements Command
{
    public function __construct(
        public string $vehicleId,
        public string $shipmentId,
    ) {}
}
