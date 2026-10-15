<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Illuminate\Contracts\Config\Repository as Config;
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
 * La carga se estima igual que el auto-planificador: cantidad de bultos del envío por el peso/volumen por
 * bulto configurables, hasta que Envíos publique peso/volumen reales. Robusto: si una tabla no existe o
 * falla, degrada a 0 (carga) o null (capacidad) para que el cálculo de métricas nunca rompa.
 */
final readonly class EloquentRouteLoadProfileProvider implements RouteLoadProfileProvider
{
    public function __construct(
        private Config $config,
    ) {}

    public function loadFor(array $shipmentIds): array
    {
        $ids = array_values(array_unique(array_filter($shipmentIds, static fn (string $id): bool => trim($id) !== '')));
        if ($ids === []) {
            return ['kg' => 0.0, 'm3' => 0.0];
        }

        $defaultWeight = $this->floatConfig('default_weight_kg_per_package', 10.0);
        $defaultVolume = $this->floatConfig('default_volume_m3_per_package', 0.05);

        try {
            $records = ShipmentRecord::query()
                ->whereIn('id', $ids)
                ->withSum('lines', 'quantity')
                ->get();
        } catch (Throwable) {
            return ['kg' => 0.0, 'm3' => 0.0];
        }

        $kg = 0.0;
        $m3 = 0.0;
        foreach ($records as $record) {
            $packagesRaw = $record->getAttribute('lines_sum_quantity');
            $packages = is_numeric($packagesRaw) ? max(1, (int) $packagesRaw) : 1;
            $kg += $packages * $defaultWeight;
            $m3 += $packages * $defaultVolume;
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

    private function floatConfig(string $key, float $default): float
    {
        $value = $this->config->get("logistics-planning.auto_planner.{$key}");

        return is_numeric($value) ? (float) $value : $default;
    }
}
