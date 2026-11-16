<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\ReadModel;

use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Logistics\Fleet\Application\Contract\CrewDirectory;
use Logistics\Fleet\Domain\ValueObject\CrewRetirementReason;
use Logistics\Fleet\Infrastructure\Persistence\CrewHistoryWriter;
use stdClass;
use Throwable;

/**
 * Lee las tripulaciones de `fleet_crews` por el query builder y resuelve placa y nombres en lote (una
 * consulta por tabla, sin N+1). Solo lectura salvo la baja de la asignación. La tabla guarda solo las
 * tripulaciones VIGENTES (las reemplazadas o quitadas pasan a `fleet_crew_history`).
 *
 * @phpstan-import-type CrewRow from CrewDirectory
 */
final readonly class DbCrewDirectory implements CrewDirectory
{
    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function list(?string $date, ?string $vehicleId, ?string $driverId, int $limit, int $offset): array
    {
        $query = $this->filtered($date, $vehicleId, $driverId);
        $total = (clone $query)->count();

        // De la más reciente a la más vieja; dentro de un día, la armada más tarde primero.
        $rows = $query->orderByDesc('assembled_for')
            ->orderByRaw('created_at desc nulls last')
            ->orderByDesc('id')
            ->limit(max(1, $limit))
            ->offset(max(0, $offset))
            ->get()
            ->all();

        return ['items' => $this->hydrate($rows), 'total' => $total];
    }

    /**
     * @return CrewRow|null
     */
    public function find(string $crewId): ?array
    {
        $row = $this->connections->connection()->table('fleet_crews')->where('id', $crewId)->first();

        return $row instanceof stdClass ? ($this->hydrate([$row])[0] ?? null) : null;
    }

    public function remove(string $crewId): bool
    {
        // Quitar no borra el rastro: la fila pasa al historial con el motivo «disuelta».
        return (new CrewHistoryWriter($this->connections->connection()))
            ->retire($crewId, CrewRetirementReason::Disbanded->value);
    }

    private function filtered(?string $date, ?string $vehicleId, ?string $driverId): Builder
    {
        $query = $this->connections->connection()->table('fleet_crews');

        if ($date !== null && $date !== '') {
            $query->whereDate('assembled_for', $date);
        }
        if ($vehicleId !== null && $vehicleId !== '') {
            $query->where('vehicle_id', $vehicleId);
        }
        if ($driverId !== null && $driverId !== '') {
            $query->where('driver_id', $driverId);
        }

        return $query;
    }

    /**
     * @param  array<int, mixed> $rows
     * @return list<CrewRow>
     */
    private function hydrate(array $rows): array
    {
        $vehicleIds = [];
        $driverIds = [];
        $helperIds = [];
        $helpersByCrew = [];

        foreach ($rows as $row) {
            if (!$row instanceof stdClass) {
                continue;
            }
            $vehicleIds[] = (string) $row->vehicle_id;
            $driverIds[] = (string) $row->driver_id;

            $decoded = is_string($row->helper_ids ?? null) ? json_decode($row->helper_ids, true) : ($row->helper_ids ?? []);
            $ids = [];
            foreach (is_array($decoded) ? $decoded : [] as $helperId) {
                if (is_string($helperId) && $helperId !== '') {
                    $ids[] = $helperId;
                    $helperIds[] = $helperId;
                }
            }
            $helpersByCrew[(string) $row->id] = $ids;
        }

        $plates = $this->names('fleet_vehicles', 'plate', $vehicleIds);
        $drivers = $this->names('fleet_drivers', 'name', $driverIds);
        $helpers = $this->names('fleet_helpers', 'name', $helperIds);

        $items = [];
        foreach ($rows as $row) {
            if (!$row instanceof stdClass) {
                continue;
            }
            $id = (string) $row->id;

            $items[] = [
                'id' => $id,
                'assembled_for' => substr((string) $row->assembled_for, 0, 10),
                'vehicle_id' => (string) $row->vehicle_id,
                'vehicle_plate' => $plates[(string) $row->vehicle_id] ?? null,
                'driver_id' => (string) $row->driver_id,
                'driver_name' => $drivers[(string) $row->driver_id] ?? null,
                'helpers' => array_map(
                    static fn (string $helperId): array => ['id' => $helperId, 'name' => $helpers[$helperId] ?? null],
                    $helpersByCrew[$id] ?? [],
                ),
                'shift_starts_at' => self::iso($row->shift_starts_at ?? null),
                'shift_ends_at' => self::iso($row->shift_ends_at ?? null),
            ];
        }

        return $items;
    }

    /**
     * Mapa id → texto legible de una tabla (placa o nombre). Tolerante: vacío si la tabla no está.
     *
     * @param  list<string>          $ids
     * @return array<string, string>
     */
    private function names(string $table, string $column, array $ids): array
    {
        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return [];
        }

        try {
            $rows = $this->connections->connection()->table($table)->whereIn('id', $ids)->get(['id', $column]);
        } catch (Throwable) {
            return [];
        }

        $map = [];
        foreach ($rows as $row) {
            $value = $row->{$column} ?? null;
            if (is_string($value) && $value !== '') {
                $map[(string) $row->id] = $value;
            }
        }

        return $map;
    }

    private static function iso(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (Throwable) {
            return null;
        }
    }
}
