<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Logistics\Coverage\Domain\Contract\DepartmentLocator;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Planning\Domain\Contract\FleetAvailabilityProvider;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;

/**
 * Adaptador que arma los camiones disponibles para el auto-planificador leyendo el contexto de Flota
 * (vehículos y tripulaciones) y resolviendo el departamento de cada camión por su nodo base vía el
 * localizador de Cobertura. Vive en Infrastructure (de solo lectura de otros contextos) y traduce al
 * contrato de dominio VehicleCapacitySpec.
 *
 * Disponible = vehículo en estado 'available'. Tripulación = existe una tripulación armada para ese
 * camión y ese día (tabla fleet_crews, assembled_for). El departamento sale del nodo base del camión;
 * si no se puede resolver, el camión queda flexible (lo liga el primer pedido que reciba).
 */
final readonly class FleetVehicleAvailabilityProvider implements FleetAvailabilityProvider
{
    /** Estado del vehículo apto para salir a ruta. */
    private const AVAILABLE_STATUS = 'available';

    public function __construct(
        private DepartmentLocator $locator,
    ) {}

    public function availableFor(PlanDate $date): array
    {
        $crewedVehicleIds = $this->crewedVehicleIdsFor($date);

        $specs = [];

        VehicleModel::query()
            ->where('status', self::AVAILABLE_STATUS)
            ->with('homeBaseNode')
            ->orderBy('id')
            ->get()
            ->each(function (VehicleModel $vehicle) use (&$specs, $crewedVehicleIds): void {
                $node = $vehicle->homeBaseNode;
                $lat = $node !== null && is_numeric($node->lat) ? (float) $node->lat : null;
                $lng = $node !== null && is_numeric($node->lng) ? (float) $node->lng : null;

                $specs[] = VehicleCapacitySpec::of(
                    vehicleId: (string) $vehicle->id,
                    capacityKg: (float) $vehicle->capacity_kg,
                    capacityM3: (float) $vehicle->capacity_m3,
                    homeBaseNodeId: (string) $vehicle->home_base_node_id,
                    hasCrew: isset($crewedVehicleIds[(string) $vehicle->id]),
                    departmentId: $this->departmentOf($vehicle),
                    homeBaseLatitude: $lat,
                    homeBaseLongitude: $lng,
                );
            });

        return $specs;
    }

    /**
     * Ids de vehículos con tripulación armada para la fecha, como mapa id => true para búsqueda O(1).
     *
     * @return array<string, true>
     */
    private function crewedVehicleIdsFor(PlanDate $date): array
    {
        $ids = [];
        foreach (CrewModel::query()->where('assembled_for', $date->toString())->pluck('vehicle_id') as $vehicleId) {
            $ids[(string) $vehicleId] = true;
        }

        return $ids;
    }

    /**
     * Departamento del camión resuelto por las coordenadas de su nodo base, o null si no se puede
     * determinar (el camión queda flexible).
     */
    private function departmentOf(VehicleModel $vehicle): ?string
    {
        $node = $vehicle->homeBaseNode;
        if ($node === null || !is_numeric($node->lat) || !is_numeric($node->lng)) {
            return null;
        }

        $location = $this->locator->locate(GeoPoint::of((float) $node->lat, (float) $node->lng));

        return $location?->departmentId;
    }
}
