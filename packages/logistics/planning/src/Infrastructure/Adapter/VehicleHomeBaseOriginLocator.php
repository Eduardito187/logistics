<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Planning\Application\Contract\RouteOriginLocator;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Throwable;

/**
 * Adaptador que resuelve la sede / almacén base de UN vehículo leyendo el contexto de Flota (VehicleModel con
 * su nodo base) y traduce a un GeoPoint de Planificación. Para un vehículo de transportadora, su nodo base es
 * su carrier_hub: de ahí sale su ruta. Vive en Infrastructure (de solo lectura de otros contextos).
 *
 * Tolerante: sin vehículo, sin nodo base o con coordenadas inválidas devuelve null (el llamador cae al
 * depósito global). Nunca lanza.
 */
final readonly class VehicleHomeBaseOriginLocator implements RouteOriginLocator
{
    public function originFor(string $vehicleId): ?GeoPoint
    {
        if (trim($vehicleId) === '') {
            return null;
        }

        try {
            /** @var VehicleModel|null $vehicle */
            $vehicle = VehicleModel::query()->with('homeBaseNode')->find(trim($vehicleId));
            if ($vehicle === null) {
                return null;
            }

            $node = $vehicle->homeBaseNode;
            if ($node === null || !is_numeric($node->lat) || !is_numeric($node->lng)) {
                return null;
            }

            return GeoPoint::of((float) $node->lat, (float) $node->lng);
        } catch (Throwable) {
            return null;
        }
    }
}
