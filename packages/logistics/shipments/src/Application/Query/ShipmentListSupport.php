<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\Query;

use Logistics\Shipments\Application\Contract\DeliveryModeReader;
use Logistics\Shipments\Application\Contract\ShipmentProgressReader;
use Logistics\Shipments\Application\ReadModel\ShipmentProgressView;
use Logistics\Shipments\Application\ReadModel\ShipmentView;
use Logistics\Shipments\Domain\ValueObject\DeliveryMode;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;

/**
 * Piezas compartidas por los listados de envíos (por offset y por cursor): normalización del filtro de
 * estado (uno o varios) y enriquecimiento de la página con el avance operativo y la modalidad de entrega.
 */
final class ShipmentListSupport
{
    /**
     * Valor del enum de estado a partir de cualquier capitalización (planned / PLANNED). Ante un estado
     * desconocido lanza ValueError, igual que antes (la puerta HTTP ya lo rechaza con 422 antes de llegar).
     */
    public static function status(string $value): string
    {
        return ShipmentStatus::fromLoose($value)->value;
    }

    /**
     * Adjunta a cada vista su avance operativo (camión, preparado, cargado, entregado, etiquetas), leído
     * en UN lote para toda la página (sin N+1).
     *
     * @param  list<ShipmentView> $views
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

    /**
     * Normaliza una lista de estados (cualquier capitalización) a valores del enum, sin repetidos. Lista
     * vacía o null → null (sin filtro). Ante un estado desconocido lanza ValueError, como status().
     *
     * @param  list<string>|null $values
     * @return list<string>|null
     */
    public static function statuses(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        $normalized = array_values(array_unique(array_map(self::status(...), $values)));

        return $normalized === [] ? null : $normalized;
    }

    /**
     * Valor normalizado del filtro de modalidad de entrega (home_delivery / store_pickup / transfer) o null.
     */
    public static function deliveryMode(?string $value): ?string
    {
        return $value !== null ? DeliveryMode::fromLoose($value)->value : null;
    }

    /**
     * Adjunta a cada vista su modalidad de entrega (domicilio / recojo en tienda / traspaso), resuelta en UN
     * lote por el tipo de los nodos destino de la página (sin N+1). Sin nodo destino = domicilio; un traspaso
     * interno (OrderSource::Transfer) con nodo destino = traspaso, nunca recojo en tienda.
     *
     * @param  list<ShipmentView> $views
     * @return list<ShipmentView>
     */
    public static function withDeliveryMode(array $views, DeliveryModeReader $reader): array
    {
        if ($views === []) {
            return [];
        }

        $nodeIds = [];
        foreach ($views as $view) {
            if ($view->destinationNodeId !== null && $view->destinationNodeId !== '') {
                $nodeIds[] = $view->destinationNodeId;
            }
        }

        $modes = $nodeIds === [] ? [] : $reader->forDestinationNodes($nodeIds);

        return array_map(
            static function (ShipmentView $view) use ($modes): ShipmentView {
                $node = $view->destinationNodeId;
                if ($node === null || $node === '') {
                    return $view->withDeliveryMode(DeliveryMode::HomeDelivery);
                }
                if (OrderSource::tryFromLoose($view->source)?->isInternalTransfer() === true) {
                    return $view->withDeliveryMode(DeliveryMode::Transfer);
                }

                return $view->withDeliveryMode($modes[$node] ?? DeliveryMode::HomeDelivery);
            },
            $views,
        );
    }
}
