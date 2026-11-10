<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

use Logistics\Execution\Application\ReadModel\DeliveryEvidenceView;

/**
 * Lectura optimizada de las evidencias de entrega de un envío (CQRS: lado de lectura). La implementación
 * vive en Infrastructure; devuelve las evidencias con su estado de validación, más recientes primero.
 */
interface DeliveryEvidenceReader
{
    /**
     * @return list<DeliveryEvidenceView>
     */
    public function forShipment(string $shipmentId): array;
}
