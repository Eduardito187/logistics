<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Logistics\Shipments\Application\Measurement\LineMeasureResolver;
use Logistics\Shipments\Application\ReadModel\CatalogMeasurements;

/**
 * Backfill de la foto de medidas en líneas de envío históricas (Roadmap 02, Fase 2.1).
 *
 * Recorre `shipment_lines` con algún null en `unit_weight_kg` / `unit_volume_m3` / `measurements_source`
 * por keyset (id UUID v7, ordenable) en tramos, resuelve la cascada producto → categoría → default con el
 * MISMO resolutor del ingreso (una lectura al catálogo por tramo) y completa SOLO los nulls (COALESCE):
 * nunca pisa una foto ya tomada. Idempotente: una segunda corrida no encuentra nada que rellenar.
 *
 * Es un adaptador de infraestructura (SQL directo por tramos, pensado para millones de filas); no pasa
 * por el agregado para no regenerar líneas/bultos.
 */
final class BackfillShipmentLineMeasurementsCommand extends Command
{
    protected $signature = 'shipments:backfill-line-measurements
        {--chunk= : Filas por tramo (por defecto, logistics-shipments.measurements.backfill_chunk)}
        {--dry-run : Solo cuenta las líneas pendientes, sin escribir}';

    protected $description = 'Completa la foto de medidas (peso/volumen/fuente) de las líneas de envío que no la tienen (solo nulls).';

    private const TABLE = 'shipment_lines';

    public function handle(ConnectionInterface $connection, LineMeasureResolver $resolver, Config $config): int
    {
        $chunkOption = $this->option('chunk');
        $chunk = is_numeric($chunkOption)
            ? (int) $chunkOption
            : (int) $config->get('logistics-shipments.measurements.backfill_chunk', 500);
        $chunk = max(1, min($chunk, 5000));

        if ((bool) $this->option('dry-run')) {
            $pending = $this->pendingQuery($connection)->count();
            $this->info("Líneas pendientes de foto de medidas: {$pending}");

            return self::SUCCESS;
        }

        $lastId = null;
        $updated = 0;

        while (true) {
            $query = $this->pendingQuery($connection)->orderBy('id')->limit($chunk);
            if ($lastId !== null) {
                $query->where('id', '>', $lastId);
            }

            $rows = $query->get(['id', 'sku']);
            if ($rows->isEmpty()) {
                break;
            }

            /** @var array<string, list<string>> $idsBySku clave normalizada del SKU => ids de línea */
            $idsBySku = [];
            foreach ($rows as $row) {
                /** @var \stdClass $row */
                $id = is_scalar($row->id ?? null) ? (string) $row->id : '';
                $sku = is_scalar($row->sku ?? null) ? (string) $row->sku : '';
                if ($id === '') {
                    continue;
                }
                $lastId = $id;
                $idsBySku[CatalogMeasurements::keyOf($sku)][] = $id;
            }

            $measures = $resolver->resolveMany(array_map('strval', array_keys($idsBySku)));

            $connection->transaction(function () use ($connection, $idsBySku, $measures, $resolver, &$updated): void {
                foreach ($idsBySku as $key => $ids) {
                    $measure = $measures[(string) $key] ?? $resolver->fallback();

                    // Solo se completan los nulls (equivale al COALESCE de antes), con los valores como
                    // bindings: nunca se interpola SQL. Mismo redondeo que las columnas (3 y 6 decimales).
                    $fill = [
                        'unit_weight_kg' => round($measure->unitWeightKg, 3),
                        'unit_volume_m3' => round($measure->unitVolumeM3, 6),
                        'measurements_source' => $measure->source->value,
                    ];
                    foreach ($fill as $column => $value) {
                        $connection->table(self::TABLE)
                            ->whereIn('id', $ids)
                            ->whereNull($column)
                            ->update([$column => $value]);
                    }

                    // Líneas visitadas del grupo (antes: filas alcanzadas por el UPDATE con COALESCE).
                    $updated += count($ids);
                }
            });

            if ($rows->count() < $chunk) {
                break;
            }
        }

        $this->info("Líneas con foto de medidas completada: {$updated}");

        return self::SUCCESS;
    }

    /**
     * Líneas a las que les falta algún dato de la foto.
     */
    private function pendingQuery(ConnectionInterface $connection): Builder
    {
        return $connection->table(self::TABLE)->where(function (Builder $query): void {
            $query->whereNull('unit_weight_kg')
                ->orWhereNull('unit_volume_m3')
                ->orWhereNull('measurements_source');
        });
    }
}
