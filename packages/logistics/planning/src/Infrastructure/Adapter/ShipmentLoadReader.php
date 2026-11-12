<?php

declare(strict_types=1);

namespace Logistics\Planning\Infrastructure\Adapter;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Lector de la CARGA REAL (kg y m3) de los envíos, de solo lectura sobre `shipment_lines` del contexto de
 * Envíos. Es la única fuente de carga de Planificación (auto-planificador, métricas de calidad y
 * comparación de backends), para que los tres midan igual.
 *
 * Contrato con Envíos (columnas aditivas y nulables en `shipment_lines`): `unit_weight_kg`,
 * `unit_volume_m3` y `measurements_source` ('product' | 'category' | 'default' | null), que son la FOTO de
 * las medidas del producto al momento de recibir el envío. Por envío:
 *
 *   kg = Σ cantidad × unit_weight_kg      m3 = Σ cantidad × unit_volume_m3
 *
 * Una línea sin valor (null) —o todas, si las columnas todavía no existen— se completa con los valores
 * por defecto POR PRODUCTO de la configuración (auto_planner.default_*), y el envío queda marcado como
 * carga ESTIMADA. También se marca estimada si alguna línea declara su medida como 'category' o 'default'
 * (no es la medida real del producto). Un envío sin líneas cuenta como un producto con los defaults
 * (comportamiento previo: mínimo un producto).
 *
 * Escala: una sola consulta agregada (GROUP BY shipment_id) por lote de ids con whereIn, sin N+1. El
 * esquema se inspecciona UNA vez por instancia (el provider la registra `scoped`: una por request/job).
 */
final class ShipmentLoadReader
{
    /** Tope de ids por consulta whereIn. */
    private const CHUNK_SIZE = 500;

    /** @var array{weight: bool, volume: bool, source: bool}|null */
    private ?array $columns = null;

    public function __construct(
        private readonly Config $config,
    ) {}

    /**
     * Carga por envío para los ids dados. Siempre devuelve una entrada por cada id pedido (los que no
     * tienen líneas o fallan, con los defaults y marcados como estimados).
     *
     * @param  list<string>                                                $shipmentIds
     * @return array<string, array{kg: float, m3: float, estimated: bool}>
     */
    public function loadsFor(array $shipmentIds): array
    {
        $ids = array_values(array_unique(array_filter(
            array_map(static fn (string $id): string => trim($id), $shipmentIds),
            static fn (string $id): bool => $id !== '',
        )));
        if ($ids === []) {
            return [];
        }

        $defaultWeight = $this->floatConfig('default_weight_kg_per_package', 10.0);
        $defaultVolume = $this->floatConfig('default_volume_m3_per_package', 0.05);

        $rows = [];
        foreach (array_chunk($ids, self::CHUNK_SIZE) as $chunk) {
            foreach ($this->aggregate($chunk) as $shipmentId => $row) {
                $rows[$shipmentId] = $row;
            }
        }

        $loads = [];
        foreach ($ids as $id) {
            $loads[$id] = $this->resolve($rows[$id] ?? null, $defaultWeight, $defaultVolume);
        }

        return $loads;
    }

    /**
     * Agregado por envío de un lote de ids. Tolerante: si la consulta falla, devuelve vacío (cada envío cae a
     * los defaults estimados) para que planificar nunca se caiga por la lectura de carga.
     *
     * @param  list<string>                                                                                                                           $ids
     * @return array<string, array{qty: int, qty_without_weight: int, qty_without_volume: int, known_kg: float, known_m3: float, approximated: bool}>
     */
    private function aggregate(array $ids): array
    {
        $columns = $this->columns();

        try {
            $query = DB::table('shipment_lines')
                ->whereIn('shipment_id', $ids)
                ->groupBy('shipment_id')
                ->select('shipment_id')
                ->selectRaw('COALESCE(SUM(quantity), 0) AS qty');

            if ($columns['weight']) {
                $query->selectRaw('COALESCE(SUM(CASE WHEN unit_weight_kg IS NULL THEN quantity ELSE 0 END), 0) AS qty_without_weight')
                    ->selectRaw('COALESCE(SUM(CASE WHEN unit_weight_kg IS NULL THEN 0 ELSE quantity * unit_weight_kg END), 0) AS known_kg');
            }
            if ($columns['volume']) {
                $query->selectRaw('COALESCE(SUM(CASE WHEN unit_volume_m3 IS NULL THEN quantity ELSE 0 END), 0) AS qty_without_volume')
                    ->selectRaw('COALESCE(SUM(CASE WHEN unit_volume_m3 IS NULL THEN 0 ELSE quantity * unit_volume_m3 END), 0) AS known_m3');
            }
            if ($columns['source']) {
                $query->selectRaw("COALESCE(SUM(CASE WHEN measurements_source IN ('category', 'default') THEN 1 ELSE 0 END), 0) AS approximated_lines");
            }

            $result = $query->get();
        } catch (Throwable) {
            return [];
        }

        $rows = [];
        foreach ($result as $row) {
            $data = (array) $row;
            $shipmentId = isset($data['shipment_id']) && is_scalar($data['shipment_id']) ? (string) $data['shipment_id'] : '';
            if ($shipmentId === '') {
                continue;
            }

            $qty = $this->intOf($data['qty'] ?? 0);

            $rows[$shipmentId] = [
                'qty' => $qty,
                // Sin la columna, todas las unidades son "sin medida" y caen al default.
                'qty_without_weight' => $columns['weight'] ? $this->intOf($data['qty_without_weight'] ?? 0) : $qty,
                'qty_without_volume' => $columns['volume'] ? $this->intOf($data['qty_without_volume'] ?? 0) : $qty,
                'known_kg' => $columns['weight'] ? $this->floatOf($data['known_kg'] ?? 0) : 0.0,
                'known_m3' => $columns['volume'] ? $this->floatOf($data['known_m3'] ?? 0) : 0.0,
                'approximated' => $columns['source'] && $this->intOf($data['approximated_lines'] ?? 0) > 0,
            ];
        }

        return $rows;
    }

    /**
     * Aplica los defaults por producto a las unidades sin medida y decide si la carga es estimada.
     *
     * @param  array{qty: int, qty_without_weight: int, qty_without_volume: int, known_kg: float, known_m3: float, approximated: bool}|null $row
     * @return array{kg: float, m3: float, estimated: bool}
     */
    private function resolve(?array $row, float $defaultWeight, float $defaultVolume): array
    {
        if ($row === null || $row['qty'] <= 0) {
            // Sin líneas (o cantidades en cero): mínimo un producto con los defaults, como antes.
            return ['kg' => $defaultWeight, 'm3' => $defaultVolume, 'estimated' => true];
        }

        $withoutWeight = max(0, $row['qty_without_weight']);
        $withoutVolume = max(0, $row['qty_without_volume']);

        return [
            'kg' => max(0.0, $row['known_kg']) + $withoutWeight * $defaultWeight,
            'm3' => max(0.0, $row['known_m3']) + $withoutVolume * $defaultVolume,
            'estimated' => $withoutWeight > 0 || $withoutVolume > 0 || $row['approximated'],
        ];
    }

    /**
     * Columnas de medidas presentes en `shipment_lines`, inspeccionadas una sola vez por instancia.
     *
     * @return array{weight: bool, volume: bool, source: bool}
     */
    private function columns(): array
    {
        if ($this->columns === null) {
            try {
                $listing = Schema::getColumnListing('shipment_lines');
            } catch (Throwable) {
                $listing = [];
            }

            $this->columns = [
                'weight' => in_array('unit_weight_kg', $listing, true),
                'volume' => in_array('unit_volume_m3', $listing, true),
                'source' => in_array('measurements_source', $listing, true),
            ];
        }

        return $this->columns;
    }

    private function intOf(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private function floatOf(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }

    private function floatConfig(string $key, float $default): float
    {
        $value = $this->config->get("logistics-planning.auto_planner.{$key}");

        return is_numeric($value) && (float) $value > 0.0 ? (float) $value : $default;
    }
}
