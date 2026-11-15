<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Contract;

/**
 * Resuelve el identificador de una etiqueta escaneada al pedido (y al envío) que representa.
 *
 * Contrato de Application: la implementación le pregunta al contexto de etiquetas por su QueryBus, de modo
 * que la consulta de pedidos no conoce cómo se guardan las etiquetas.
 */
interface ScannedLabelResolver
{
    /**
     * Pedido y envío de la etiqueta, o null si ese identificador no es una etiqueta emitida.
     *
     * @return array{order_number: string|null, shipment_id: string|null}|null
     */
    public function resolve(string $labelId): ?array;
}
