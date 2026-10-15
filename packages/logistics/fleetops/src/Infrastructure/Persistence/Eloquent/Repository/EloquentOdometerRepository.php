<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\OdometerRepository;
use Logistics\FleetOps\Domain\Model\OdometerReading;
use Logistics\FleetOps\Domain\ValueObject\OdometerReadingId;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper\OdometerReadingMapper;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\OdometerReadingRecord;

/**
 * Implementación Eloquent del repositorio de lecturas de odómetro. `save` es un upsert por id.
 */
final readonly class EloquentOdometerRepository implements OdometerRepository
{
    public function __construct(
        private OdometerReadingMapper $mapper = new OdometerReadingMapper,
    ) {}

    public function nextIdentity(): OdometerReadingId
    {
        return OdometerReadingId::generate();
    }

    public function save(OdometerReading $reading): void
    {
        $row = $this->mapper->toRow($reading);
        OdometerReadingRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function latestForVehicle(string $vehicleId): ?OdometerReading
    {
        $record = OdometerReadingRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('read_at')
            ->first();

        return $record instanceof OdometerReadingRecord ? $this->mapper->toDomain($record) : null;
    }

    public function listBy(
        ?string $vehicleId = null,
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): array {
        $query = OdometerReadingRecord::query();

        if ($vehicleId !== null) {
            $query->where('vehicle_id', $vehicleId);
        }
        if ($from !== null) {
            $query->where('read_at', '>=', $from);
        }
        if ($to !== null) {
            $query->where('read_at', '<=', $to);
        }

        return array_values($query
            ->orderByDesc('read_at')
            ->get()
            ->map(fn (OdometerReadingRecord $record): OdometerReading => $this->mapper->toDomain($record))
            ->all());
    }
}
