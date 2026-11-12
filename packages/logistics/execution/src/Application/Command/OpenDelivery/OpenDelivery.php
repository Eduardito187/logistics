<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\OpenDelivery;

use Logistics\Shared\Application\Command\Command;

/**
 * Abre la entrega de un envío al asignarle el camión de reparto (flujo nuevo del dueño: asignar vehículo
 * ES preparar; ya no hay escaneo de preparación del preparador). Sin una entrega abierta, el escaneo de
 * carga y el POD del chofer fallan en dominio ("no hay entrega abierta para el envío").
 *
 * Idempotente: si el envío ya tiene entrega, no hace nada (no duplica ni reabre).
 */
final readonly class OpenDelivery implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $vehicleId,
    ) {}
}
