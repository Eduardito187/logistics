<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Illuminate\Support\Facades\Date;
use Logistics\Telemetry\Domain\Contract\PingRepository;
use Logistics\Telemetry\Domain\Model\VehiclePing;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Mapper\PingMapper;
use Logistics\Telemetry\Infrastructure\Persistence\Eloquent\Model\PingRecord;

/**
 * Implementación Eloquent del repositorio de pings (append-only). No hay update ni delete.
 *
 * `appendMany` inserta en bloque por chunks para soportar la ingesta de alto volumen sin instanciar un
 * modelo por fila. El tamaño de chunk es conservador para no reventar el límite de parámetros del driver.
 */
final readonly class EloquentPingRepository implements PingRepository
{
    private const INSERT_CHUNK = 500;

    public function __construct(
        private PingMapper $mapper = new PingMapper,
    ) {}

    public function append(VehiclePing $ping): void
    {
        PingRecord::query()->create($this->mapper->toRow($ping));
    }

    public function appendMany(array $pings): void
    {
        if ($pings === []) {
            return;
        }

        $now = Date::now();

        $rows = array_map(function (VehiclePing $ping) use ($now): array {
            $row = $this->mapper->toRow($ping);
            $row['created_at'] = $now;
            $row['updated_at'] = $now;

            return $row;
        }, $pings);

        foreach (array_chunk($rows, self::INSERT_CHUNK) as $chunk) {
            PingRecord::query()->insert($chunk);
        }
    }

    public function latestForVehicle(string $vehicleId): ?VehiclePing
    {
        $record = PingRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->orderByDesc('recorded_at')
            ->first();

        return $record instanceof PingRecord ? $this->mapper->toDomain($record) : null;
    }

    public function track(string $vehicleId, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        return array_values(PingRecord::query()
            ->where('vehicle_id', $vehicleId)
            ->whereBetween('recorded_at', [$from, $to])
            ->orderBy('recorded_at')
            ->get()
            ->map(fn (PingRecord $record): VehiclePing => $this->mapper->toDomain($record))
            ->all());
    }

    public function allVehicleIdsWithPingsOn(DateTimeImmutable $day): array
    {
        $from = $day->setTime(0, 0, 0);
        $to = $from->modify('+1 day')->modify('-1 second');

        /** @var list<string> $ids */
        $ids = PingRecord::query()
            ->whereBetween('recorded_at', [$from, $to])
            ->distinct()
            ->orderBy('vehicle_id')
            ->pluck('vehicle_id')
            ->all();

        return $ids;
    }
}
