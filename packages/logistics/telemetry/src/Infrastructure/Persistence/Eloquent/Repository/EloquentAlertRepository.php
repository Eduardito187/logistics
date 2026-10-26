<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository;

use Illuminate\Database\Eloquent\Builder;
use Logistics\Telemetry\Domain\Contract\AlertRepository;
use Logistics\Telemetry\Domain\Model\TelemetryAlert;
use Logistics\Telemetry\Domain\ValueObject\AlertId;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper\AlertMapper;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\AlertRecord;

/**
 * Implementación Eloquent del repositorio de alertas. `save` es un upsert por id.
 */
final readonly class EloquentAlertRepository implements AlertRepository
{
    public function __construct(
        private AlertMapper $mapper = new AlertMapper,
    ) {}

    public function nextIdentity(): AlertId
    {
        return AlertId::generate();
    }

    public function save(TelemetryAlert $alert): void
    {
        $row = $this->mapper->toRow($alert);
        AlertRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(AlertId $id): ?TelemetryAlert
    {
        $record = AlertRecord::query()->find($id->toString());

        return $record instanceof AlertRecord ? $this->mapper->toDomain($record) : null;
    }

    public function listBy(
        ?string $vehicleId = null,
        ?string $type = null,
        ?bool $acknowledged = null,
        ?int $limit = null,
        ?int $offset = null,
    ): array {
        $query = $this->filtered($vehicleId, $type, $acknowledged)->orderByDesc('occurred_at');

        if ($offset !== null) {
            $query->offset($offset);
        }
        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query
            ->get()
            ->map(fn (AlertRecord $record): TelemetryAlert => $this->mapper->toDomain($record))
            ->all());
    }

    public function countBy(?string $vehicleId = null, ?string $type = null, ?bool $acknowledged = null): int
    {
        return $this->filtered($vehicleId, $type, $acknowledged)->count();
    }

    /**
     * Query builder con los filtros comunes de listBy/countBy (sin orden ni recorte).
     *
     * @return Builder<AlertRecord>
     */
    private function filtered(?string $vehicleId, ?string $type, ?bool $acknowledged): Builder
    {
        $query = AlertRecord::query();

        if ($vehicleId !== null && $vehicleId !== '') {
            $query->where('vehicle_id', $vehicleId);
        }
        if ($type !== null && $type !== '') {
            $query->where('type', $type);
        }
        if ($acknowledged === true) {
            $query->whereNotNull('acknowledged_at');
        } elseif ($acknowledged === false) {
            $query->whereNull('acknowledged_at');
        }

        return $query;
    }
}
