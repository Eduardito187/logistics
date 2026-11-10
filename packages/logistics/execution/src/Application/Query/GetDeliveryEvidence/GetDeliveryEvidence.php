<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Query\GetDeliveryEvidence;

use Logistics\Shared\Application\Query\Query;

/**
 * Consulta las evidencias de entrega de un envío (firma, documento, POD, daño) con su ESTADO DE
 * VALIDACIÓN anti-fraude. La app la usa para mostrar "pendiente / validado / revisar / no coincide".
 */
final readonly class GetDeliveryEvidence implements Query
{
    public function __construct(
        public string $shipmentId,
    ) {}
}
