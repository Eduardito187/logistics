<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\ValueObject;

/**
 * Problema de ruteo reconstruido a partir de UN plan para comparar backends (R-06): el origen (CD), los
 * pedidos del plan como candidatos (con sus coordenadas y carga) y el camión del plan como único vehículo
 * disponible. Se le pasa a cada RoutingBackend (builtin/osrm/vroom) para ver cuánta distancia/costo
 * produce cada uno sobre el MISMO conjunto de paradas.
 *
 * Dato puro e inmutable. Lo arma quien conoce los envíos y la flota (adaptador de Infrastructure); el
 * dominio no consulta otros contextos.
 */
final readonly class RouteComparisonProblem
{
    /**
     * @param list<DeliveryCandidate> $candidates pedidos del plan (coordenadas + carga)
     */
    public function __construct(
        public PlanDate $date,
        public ?GeoPoint $origin,
        public array $candidates,
        public VehicleCapacitySpec $vehicle,
    ) {}

    public function stopCount(): int
    {
        return count($this->candidates);
    }
}
