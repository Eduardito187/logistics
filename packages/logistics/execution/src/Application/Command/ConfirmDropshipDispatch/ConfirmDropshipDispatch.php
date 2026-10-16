<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\ConfirmDropshipDispatch;

use Logistics\Shared\Application\Command\Command;

/**
 * El seller (o el transportista tercero) confirma que DESPACHÓ un envío dropship. Abre la entrega en
 * modo dropship si aún no existe y la pone en tránsito, sin escaneos propios de Dismac.
 *
 * `carrierTransportId` es el id del transporte que lleva el envío (del seller o del tercero); se guarda en
 * el campo de vehículo de la entrega, que el dominio trata como id opaco. `handling` es track_only o
 * third_party_carrier (last_mile usa el flujo propio con escaneos, no esta confirmación).
 *
 * Idempotente por idempotencyKey. La bifurcación la decide el fulfillment_mode del envío (debe ser dropship).
 */
final readonly class ConfirmDropshipDispatch implements Command
{
    public function __construct(
        public string $shipmentId,
        public string $carrierTransportId,
        public string $handling,
        public string $occurredAt,
        public string $idempotencyKey,
        public ?string $reference = null,
    ) {}
}
