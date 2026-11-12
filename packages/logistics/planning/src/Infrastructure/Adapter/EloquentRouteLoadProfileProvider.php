<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Logistics\Fleet\Infrastructure\Persistence\Eloquent\Model\VehicleModel;
use Logistics\Planning\Domain\Contract\RouteLoadProfileProvider;
use Logistics\Shipments\Infrastructure\Persistence\Eloquent\ShipmentRecord;
use Throwable;

/**
 * Adaptador de las métricas de calidad (R-06): resuelve la CARGA de una ruta leyendo los envíos (contexto
 * Envíos) y la CAPACIDAD del camión leyendo la flota (contexto Flota), todo read-only y en lote. Vive en
 * Infrastructure: conoce los esquemas de otros contextos (nunca los escribe) y traduce al contrato de
 * dominio.
 *
 * La carga se mide igual que el auto-planificador (ShipmentLoadReader): kg/m3 reales por línea del envío,
 * con fallback a los defaults por producto para las líneas sin medidas. Robusto: si una tabla no existe o
 * falla, degrada a 0 (carga) o null (capacidad) para que el cálculo de métricas nunca rompa.
 */
final readonly class EloquentRouteLoadProfileProvider implements RouteLoadProfileProvider
{
    public function __construct(
        private ShipmentLoadReader $loads,
    ) {}

    public function loadFor(array $shipmentIds): array
    {
        $ids = array_values(array_unique(array_filter($shipmentIds, static fn (string $id): bool => trim($id) !== '')));
        if ($ids === []) {
            return ['kg' => 0.0, 'm3' => 0.0];
        }

        // Solo cuentan los envíos que existen (mismo criterio que antes: un id inexistente no suma carga).
        try {
            $existing = [];
            foreach (ShipmentRecord::query()->whereIn('id', $ids)->pluck('id') as $id) {
                $existing[] = (string) $id;
            }
        } catch (Throwable) {
            return ['kg' => 0.0, 'm3' => 0.0];
        }

        $kg = 0.0;
        $m3 = 0.0;
        foreach ($this->loads->loadsFor($existing) as $load) {
            $kg += $load['kg'];
            $m3 += $load['m3'];
        }

        return ['kg' => $kg, 'm3' => $m3];
    }

    public function capacityFor(string $vehicleId): ?array
    {
        if (trim($vehicleId) === '') {
            return null;
        }

        try {
            $vehicle = VehicleModel::query()->find(trim($vehicleId));
        } catch (Throwable) {
            return null;
        }

        if ($vehicle === null || !is_numeric($vehicle->capacity_kg) || !is_numeric($vehicle->capacity_m3)) {
            return null;
        }

        return ['kg' => (float) $vehicle->capacity_kg, 'm3' => (float) $vehicle->capacity_m3];
    }
}
