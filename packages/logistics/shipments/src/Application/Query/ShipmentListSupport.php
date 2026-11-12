<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query;

use Logistics\Shipments\Application\Contract\ShipmentProgressReader;
use Logistics\Shipments\Application\ReadModel\ShipmentProgressView;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;

/**
 * Piezas compartidas por los listados de envíos (por offset y por cursor): normalización del filtro de
 * estado y enriquecimiento de la página con el avance operativo.
 */
final class ShipmentListSupport
{
    /**
     * Valor del enum de estado a partir de cualquier capitalización (planned / PLANNED). Ante un estado
     * desconocido lanza ValueError, igual que antes (la puerta HTTP ya lo rechaza con 422 antes de llegar).
     */
    public static function status(string $value): string
    {
        return ShipmentStatus::from(strtoupper(trim($value)))->value;
    }

    /**
     * Adjunta a cada vista su avance operativo (camión, preparado, cargado, entregado, etiquetas), leído
     * en UN lote para toda la página (sin N+1).
     *
     * @param list<ShipmentView> $views
     *
     * @return list<ShipmentView>
     */
    public static function withProgress(array $views, ShipmentProgressReader $reader): array
    {
        if ($views === []) {
            return [];
        }

        $progress = $reader->forShipmentIds(array_map(static fn (ShipmentView $view): string => $view->id, $views));

        return array_map(
            static fn (ShipmentView $view): ShipmentView => $view->withProgress($progress[$view->id] ?? ShipmentProgressView::unknown()),
            $views,
        );
    }
}
