<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use DateTimeImmutable;
use Logistics\Planning\Domain\ValueObject\AutoPlanParameters;
use Logistics\Planning\Domain\ValueObject\DeliveryCandidate;
use Logistics\Planning\Domain\ValueObject\GeoPoint;
use Logistics\Planning\Domain\ValueObject\RoutingResult;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;

/**
 * Backend ENCHUFABLE de optimización de ruteo del auto-planificador: a partir de los pedidos pendientes y
 * los camiones disponibles, asigna paradas a camiones (respetando capacidad y la regla "un camión = un
 * departamento") y resuelve el orden de reparto, la ETA y la geometría de cada ruta.
 *
 * El dominio define QUÉ necesita; la elección del backend concreto —'builtin' (Clarke-Wright + 2-opt,
 * por defecto), 'osrm_trip' (OSRM /trip por camión) o 'vroom' (servidor VROOM)— es una decisión de
 * configuración que se resuelve en Infrastructure. Así se puede mejorar o reemplazar el núcleo de ruteo
 * sin tocar al runner que lo consume.
 */
interface RoutingBackend
{
    /**
     * Resuelve las rutas del día.
     *
     * @param list<DeliveryCandidate>   $candidates pedidos pendientes a planificar
     * @param list<VehicleCapacitySpec> $vehicles   camiones disponibles
     * @param GeoPoint|null             $origin     origen (CD) de las rutas, si se conoce; si es null, cada
     *                                              grupo usa un depósito aproximado derivado de sus paradas
     */
    public function route(
        DateTimeImmutable $departAt,
        ?GeoPoint $origin,
        array $candidates,
        array $vehicles,
        AutoPlanParameters $params,
    ): RoutingResult;
}
