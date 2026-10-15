<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Illuminate\Support\Facades\DB;
use Logistics\Coverage\Domain\Contract\DepartmentLocator;
use Logistics\Coverage\Domain\ValueObject\GeoPoint;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\CrewModel;
use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Planning\Domain\Contract\FleetAvailabilityProvider;
use Logistics\Planning\Domain\ValueObject\PlanDate;
use Logistics\Planning\Domain\ValueObject\VehicleCapacitySpec;
use Logistics\Planning\Domain\ValueObject\VehicleRouteProfile;
use Throwable;

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
        $restrictions = $this->restrictionProfiles();

        $specs = [];

        VehicleModel::query()
            ->where('status', self::AVAILABLE_STATUS)
            ->with('homeBaseNode')
            ->orderBy('id')
            ->get()
            ->each(function (VehicleModel $vehicle) use (&$specs, $crewedVehicleIds, $restrictions): void {
                $node = $vehicle->homeBaseNode;
                $lat = $node !== null && is_numeric($node->lat) ? (float) $node->lat : null;
                $lng = $node !== null && is_numeric($node->lng) ? (float) $node->lng : null;
                $vehicleId = (string) $vehicle->id;

                $specs[] = VehicleCapacitySpec::of(
                    vehicleId: $vehicleId,
                    capacityKg: (float) $vehicle->capacity_kg,
                    capacityM3: (float) $vehicle->capacity_m3,
                    homeBaseNodeId: (string) $vehicle->home_base_node_id,
                    hasCrew: isset($crewedVehicleIds[$vehicleId]),
                    departmentId: $this->departmentOf($vehicle),
                    homeBaseLatitude: $lat,
                    homeBaseLongitude: $lng,
                    profile: $restrictions[$vehicleId] ?? null,
                );
            });

        return $specs;
    }

    /**
     * Perfiles de restricción de ruteo (R-04) por vehículo, leídos EN LOTE del read-side propio de
     * Planificación (planning_vehicle_restrictions). Tolerante: si la tabla aún no existe (migración sin
     * aplicar), degrada a sin restricciones para no romper la planificación.
     *
     * @return array<string, VehicleRouteProfile>
     */
    private function restrictionProfiles(): array
    {
        try {
            $rows = DB::table('planning_vehicle_restrictions')->get();
        } catch (Throwable) {
            return [];
        }

        $profiles = [];
        foreach ($rows as $row) {
            $data = (array) $row;
            $vehicleId = isset($data['vehicle_id']) ? (string) $data['vehicle_id'] : '';
            if ($vehicleId === '') {
                continue;
            }

            $profiles[$vehicleId] = VehicleRouteProfile::fromArray([
                'allowed_load_types' => $this->decodeJson($data['allowed_load_types'] ?? null),
                'allowed_zones' => $this->decodeJson($data['allowed_zones'] ?? null),
                'forbidden_zones' => $this->decodeJson($data['forbidden_zones'] ?? null),
                'max_height_m' => $data['max_height_m'] ?? null,
                'max_weight_kg' => $data['max_weight_kg'] ?? null,
            ]);
        }

        return $profiles;
    }

    /**
     * Decodifica una columna jsonb que puede llegar como string (PostgreSQL vía query builder) o como
     * array ya decodificado; cualquier otra cosa vuelve null (sin restricción).
     */
    private function decodeJson(mixed $value): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value) && $value !== '') {
            return json_decode($value, true);
        }

        return null;
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
