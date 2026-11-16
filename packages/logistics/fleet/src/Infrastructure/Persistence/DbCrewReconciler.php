<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Persistence;

use Illuminate\Database\ConnectionInterface;
use Logistics\Fleet\Application\Contract\CrewReconciler;
use Logistics\Fleet\Domain\ValueObject\CrewRetirementReason;
use stdClass;
use Throwable;

/**
 * Saneo de duplicados de `fleet_crews` con el query builder, por lotes de grupos. Cada grupo se resuelve
 * en su propia transacción con las filas bloqueadas; la más reciente (created_at, y a igualdad la id
 * UUID v7 más alta) queda vigente y las demás pasan a `fleet_crew_history` con origen `backfill`.
 *
 * Al aplicar, si ya no quedan duplicados asegura los índices únicos (los mismos de la migración): es la
 * segunda oportunidad si la migración no pudo crearlos.
 *
 * @phpstan-import-type ReconcileReport from CrewReconciler
 */
final readonly class DbCrewReconciler implements CrewReconciler
{
    private const string DRIVER_COLUMN = 'driver_id';

    private const string VEHICLE_COLUMN = 'vehicle_id';

    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function reconcile(bool $apply, int $chunk): array
    {
        $chunk = max(1, $chunk);

        // Primero por chofer (define el «camión cambiado»); después por vehículo, sobre lo que quedó.
        $drivers = $this->pass(self::DRIVER_COLUMN, CrewRetirementReason::Replaced, $apply, $chunk);
        $vehicles = $this->pass(self::VEHICLE_COLUMN, CrewRetirementReason::Displaced, $apply, $chunk);

        return [
            'driver_groups' => $drivers['groups'],
            'driver_crews' => $drivers['crews'],
            'vehicle_groups' => $vehicles['groups'],
            'vehicle_crews' => $vehicles['crews'],
            'indexes_ready' => $apply ? $this->ensureIndexes() : $this->indexesExist(),
        ];
    }

    /**
     * Recorre los grupos (columna + fecha) con más de una tripulación. Aplicando, cada lote resuelto
     * desaparece de la consulta, así que siempre se pide la primera página; simulando, se avanza por offset.
     *
     * @return array{groups: int, crews: int}
     */
    private function pass(string $column, CrewRetirementReason $reason, bool $apply, int $chunk): array
    {
        $groups = 0;
        $crews = 0;
        $offset = 0;

        do {
            $page = $this->db->table('fleet_crews')
                ->select([$column, 'assembled_for'])
                ->selectRaw('count(*) as crews')
                ->groupBy($column, 'assembled_for')
                ->havingRaw('count(*) > 1')
                ->orderBy('assembled_for')
                ->orderBy($column)
                ->limit($chunk)
                ->offset($offset)
                ->get()
                ->all();

            $resolved = 0;
            foreach ($page as $group) {
                if (!$group instanceof stdClass) {
                    continue;
                }
                $extra = max(0, (int) $group->crews - 1);
                $resolved += $apply
                    ? $this->resolveGroup($column, (string) $group->{$column}, (string) $group->assembled_for, $reason)
                    : $extra;
                $groups++;
            }

            $crews += $resolved;
            $offset += $apply ? 0 : $chunk;
            // Aplicando, un lote que no resolvió nada no va a cambiar al repetirlo: se corta para no girar.
            $more = count($page) === $chunk && (!$apply || $resolved > 0);
        } while ($more);

        return ['groups' => $groups, 'crews' => $crews];
    }

    /**
     * Deja vigente la más reciente del grupo y retira las demás. Devuelve cuántas retiró.
     */
    private function resolveGroup(string $column, string $value, string $date, CrewRetirementReason $reason): int
    {
        return (int) $this->db->transaction(function () use ($column, $value, $date, $reason): int {
            $rows = $this->db->table('fleet_crews')
                ->where($column, $value)
                ->where('assembled_for', substr($date, 0, 10))
                ->orderByRaw('created_at desc nulls last')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->get(['id', 'vehicle_id', 'created_at'])
                ->all();

            $keeper = array_shift($rows);
            if (!$keeper instanceof stdClass) {
                return 0;
            }

            $writer = new CrewHistoryWriter($this->db);
            $retired = 0;
            foreach ($rows as $row) {
                $done = $writer->retire(
                    (string) $row->id,
                    $reason->value,
                    (string) $keeper->id,
                    // El camión nuevo solo tiene sentido cuando es el mismo chofer que cambió de camión.
                    $reason === CrewRetirementReason::Replaced ? (string) $keeper->vehicle_id : null,
                    CrewHistoryWriter::SOURCE_BACKFILL,
                    // El cambio ocurrió cuando se armó la que quedó, no cuando corre el saneo.
                    is_string($keeper->created_at ?? null) ? $keeper->created_at : null,
                );
                $retired += $done ? 1 : 0;
            }

            return $retired;
        });
    }

    /**
     * Crea los índices únicos si faltan. Si todavía hay duplicados (entró uno nuevo en el medio) no falla:
     * devuelve false y la próxima corrida lo resuelve.
     */
    private function ensureIndexes(): bool
    {
        try {
            $this->db->statement('CREATE UNIQUE INDEX IF NOT EXISTS fleet_crews_driver_day_unique ON fleet_crews (driver_id, assembled_for)');
            $this->db->statement('CREATE UNIQUE INDEX IF NOT EXISTS fleet_crews_vehicle_day_unique ON fleet_crews (vehicle_id, assembled_for)');
        } catch (Throwable) {
            return false;
        }

        return true;
    }

    private function indexesExist(): bool
    {
        return $this->db->table('pg_indexes')
            ->where('tablename', 'fleet_crews')
            ->whereIn('indexname', ['fleet_crews_driver_day_unique', 'fleet_crews_vehicle_day_unique'])
            ->count() === 2;
    }
}
