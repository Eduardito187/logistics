<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Contract;

/**
 * Puerto que resuelve el PUNTO DE SALIDA de la ruta de un vehículo: su nodo sede / almacén base (de dónde
 * arranca ese camión). Para un vehículo de transportadora, su sede es su carrier_hub. El dominio/aplicación
 * define QUÉ necesita (un punto para anteponer a las paradas y que la polilínea del chofer empiece en su
 * almacén de salida, no en el origen del envío); la implementación, que lee la sede del vehículo en Flota/Red,
 * vive en Infrastructure.
 */
interface RouteOriginLocator
{
    /**
     * Coordenada [lng, lat] de la sede del vehículo (mismo orden que las paradas de la ruta), o null si no
     * hay vehículo o no se pudo resolver su sede (entonces la ruta mantiene el comportamiento actual: arranca
     * en la primera parada).
     *
     * @return array{0: float, 1: float}|null
     */
    public function originFor(string $vehicleId): ?array;
}
