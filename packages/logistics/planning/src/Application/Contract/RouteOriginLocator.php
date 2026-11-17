<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\Contract;

use Logistics\Planning\Domain\ValueObject\GeoPoint;

/**
 * Puerto que resuelve el PUNTO DE SALIDA de la ruta de un vehículo: su nodo sede / almacén base (de dónde
 * arranca ese camión). Para un vehículo de transportadora, su sede es su carrier_hub. Se antepone a las
 * paradas al calcular la geometría del plan para que la polilínea arranque en el almacén de salida del
 * vehículo, no en el origen del envío.
 *
 * A diferencia de `PlanningOriginLocator` (depósito GLOBAL único, por `originNodeId` de AutoPlanSettings),
 * este resuelve la sede POR VEHÍCULO. `PlanGeometryRefresher` lo prefiere y cae al depósito global cuando la
 * sede del vehículo no se resuelve.
 */
interface RouteOriginLocator
{
    /**
     * Coordenadas de la sede del vehículo, o null si no hay vehículo o no se pudo resolver su sede (entonces
     * el llamador cae al depósito global actual; sin él, la línea arranca en la primera parada).
     */
    public function originFor(string $vehicleId): ?GeoPoint;
}
