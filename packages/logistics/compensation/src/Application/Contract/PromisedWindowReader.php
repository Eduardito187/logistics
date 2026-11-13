<?php

declare(strict_types=1);

namespace Logistics\Compensation\Application\Contract;

use DateTimeImmutable;

/**
 * Puerto de lectura de la ventana de entrega PROMETIDA de un envío (fecha + fin de ventana), para decidir
 * si una entrega fue tardía. La implementación consulta la proyección de shipments; devuelve null si no hay
 * ventana prometida registrada.
 */
interface PromisedWindowReader
{
    public function promisedEndFor(string $shipmentId): ?DateTimeImmutable;

    /**
     * Indica si la entrega del envío fue reprogramada a pedido del cliente (excluye del cálculo de tardía).
     */
    public function wasRescheduledByCustomer(string $shipmentId): bool;
}
