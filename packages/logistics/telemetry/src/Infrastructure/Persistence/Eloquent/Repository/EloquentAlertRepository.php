<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository;

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

    public function listBy(?string $vehicleId = null, ?string $type = null, ?bool $acknowledged = null): array
    {
        $query = AlertRecord::query()->orderByDesc('occurred_at');

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

        return array_values($query
            ->get()
            ->map(fn (AlertRecord $record): TelemetryAlert => $this->mapper->toDomain($record))
            ->all());
    }
}
