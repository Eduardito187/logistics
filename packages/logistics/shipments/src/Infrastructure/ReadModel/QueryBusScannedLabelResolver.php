<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\ReadModel;

use Logistics\Labels\Application\Query\ResolveLabelUnit\ResolveLabelUnit;
use Logistics\Labels\Application\ReadModel\LabelUnitView;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shipments\Application\Contract\ScannedLabelResolver;
use Throwable;

/**
 * Resuelve una etiqueta escaneada preguntándole al contexto de etiquetas por su QueryBus (consulta
 * ResolveLabelUnit, la misma de GET /app/labels/{labelId}/resolve). Es el único punto de shipments que
 * conoce ese contrato; no lee las tablas de etiquetas.
 *
 * Tolerante: si el contexto de etiquetas no responde, la etiqueta se trata como desconocida (la consulta
 * de pedidos sigue con la búsqueda por texto).
 */
final readonly class QueryBusScannedLabelResolver implements ScannedLabelResolver
{
    public function __construct(
        private QueryBus $queryBus,
    ) {}

    public function resolve(string $labelId): ?array
    {
        $labelId = trim($labelId);
        if ($labelId === '') {
            return null;
        }

        try {
            $view = $this->queryBus->ask(new ResolveLabelUnit($labelId));
        } catch (Throwable) {
            return null;
        }

        if (!$view instanceof LabelUnitView) {
            return null;
        }

        $orderNumber = $view->orderNumber !== null && trim($view->orderNumber) !== '' ? trim($view->orderNumber) : null;
        $shipmentId = $view->shipmentId !== null && trim($view->shipmentId) !== '' ? trim($view->shipmentId) : null;

        return $orderNumber === null && $shipmentId === null
            ? null
            : ['order_number' => $orderNumber, 'shipment_id' => $shipmentId];
    }
}
