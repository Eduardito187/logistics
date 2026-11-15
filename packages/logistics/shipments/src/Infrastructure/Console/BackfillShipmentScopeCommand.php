<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Console;

use Illuminate\Console\Command;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Logistics\Shipments\Infrastructure\ReadModel\ShipmentScopeFieldsResolver;
use stdClass;

/**
 * Completa los campos de alcance (`origin_node_id`, `origin_department`, `destination_department`) en los
 * envíos que todavía no los tienen (los anteriores a las columnas).
 *
 * Idempotente: solo mira filas a las que les falta alguno de los tres campos y solo escribe los que están
 * vacíos (un origen ya informado nunca se pisa), así que correrlo varias veces no cambia nada. Escribe con
 * el query builder —sin pasar por el agregado ni reemitir sus hechos al outbox— y por lotes, leyendo la red
 * una sola vez. Simulación por defecto; `--force` aplica.
 */
final class BackfillShipmentScopeCommand extends Command
{
    private const array COLUMNS = ['origin_node_id', 'origin_department', 'destination_department'];

    protected $signature = 'logistics:shipments:backfill-scope
        {--force : Aplica los cambios (sin esto es simulación)}
        {--chunk=1000 : Tamaño de lote}';

    protected $description = 'Completa el origen y los departamentos de origen y destino de los envíos que no los tienen';

    public function handle(ConnectionResolverInterface $connections, ShipmentScopeFieldsResolver $resolver): int
    {
        $force = $this->option('force') === true;
        $chunk = max(1, (int) $this->option('chunk'));
        $db = $connections->connection();

        $nodes = $resolver->networkNodes();
        if ($nodes === []) {
            $this->warn('La red no tiene nodos (o no se pudo leer): no hay con qué resolver el alcance.');

            return self::SUCCESS;
        }

        $updated = 0;
        $unresolved = 0;

        $db->table('shipments')
            ->where(static function (Builder $missing): void {
                foreach (self::COLUMNS as $column) {
                    $missing->orWhereNull($column);
                }
            })
            ->orderBy('id')
            ->select(['id', 'destination_node_id', 'city', 'lat', 'lng', 'order_reference', 'external_id', ...self::COLUMNS])
            ->chunkById($chunk, function (iterable $rows) use ($db, $resolver, $nodes, $force, &$updated, &$unresolved): void {
                foreach ($rows as $row) {
                    if (!$row instanceof stdClass) {
                        continue;
                    }

                    $changes = $this->changesFor($row, $resolver, $nodes);
                    if ($changes === []) {
                        $unresolved++;

                        continue;
                    }

                    $updated++;
                    if ($force) {
                        // Cada columna se escribe solo si sigue vacía (otra escritura pudo completarla).
                        foreach ($changes as $column => $value) {
                            $db->table('shipments')->where('id', $row->id)->whereNull($column)->update([$column => $value]);
                        }
                    }
                }
            });

        $this->info(($force ? 'Listo. ' : 'Simulación. ')."Envíos con alcance completado: {$updated}. Sin dato para resolver: {$unresolved}.".($force ? '' : ' Usá --force para aplicar.'));

        return self::SUCCESS;
    }

    /**
     * Columnas vacías del envío que se pueden completar, con su valor.
     *
     * @param  array<string, array{id: string, code: string, type: string, department: string|null, lat: float|null, lng: float|null}> $nodes
     * @return array<string, string>
     */
    private function changesFor(stdClass $row, ShipmentScopeFieldsResolver $resolver, array $nodes): array
    {
        $fields = $resolver->resolveWith(
            $nodes,
            self::text($row->destination_node_id ?? null),
            self::text($row->city ?? null),
            is_numeric($row->lat ?? null) ? (float) $row->lat : null,
            is_numeric($row->lng ?? null) ? (float) $row->lng : null,
            self::text($row->order_reference ?? null) ?? self::text($row->external_id ?? null),
            self::text($row->origin_node_id ?? null),
        );

        $changes = [];
        foreach (self::COLUMNS as $column) {
            $resolved = $fields[$column];
            if ($resolved !== null && self::text($row->{$column} ?? null) === null) {
                $changes[$column] = $resolved;
            }
        }

        return $changes;
    }

    private static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
