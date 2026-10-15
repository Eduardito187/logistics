<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Carriers\Domain\Contract\TariffRepository;
use Logistics\Carriers\Domain\Model\Tariff;
use Logistics\Carriers\Domain\ValueObject\CorridorRef;
use Logistics\Carriers\Domain\ValueObject\TariffId;
use Logistics\Carriers\Domain\ValueObject\VehicleClass;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Mapper\TariffMapper;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\TariffRecord;

/**
 * Implementación Eloquent del repositorio de tarifas. `save` es un upsert por id.
 */
final readonly class EloquentTariffRepository implements TariffRepository
{
    public function __construct(
        private TariffMapper $mapper = new TariffMapper,
    ) {}

    public function nextIdentity(): TariffId
    {
        return TariffId::generate();
    }

    public function save(Tariff $tariff): void
    {
        $row = $this->mapper->toRow($tariff);
        TariffRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(TariffId $id): ?Tariff
    {
        $record = TariffRecord::query()->find($id->toString());

        return $record instanceof TariffRecord ? $this->mapper->toDomain($record) : null;
    }

    public function findTariff(string $carrierId, CorridorRef $corridor, VehicleClass $class): ?Tariff
    {
        $record = TariffRecord::query()
            ->where('carrier_id', $carrierId)
            ->where('corridor_from', $corridor->from)
            ->where('corridor_to', $corridor->to)
            ->where('corridor_type', $corridor->type)
            ->where('vehicle_class', $class->value)
            ->first();

        return $record instanceof TariffRecord ? $this->mapper->toDomain($record) : null;
    }

    public function cheapestForCorridor(CorridorRef $corridor): ?Tariff
    {
        $record = TariffRecord::query()
            ->where('corridor_from', $corridor->from)
            ->where('corridor_to', $corridor->to)
            ->where('corridor_type', $corridor->type)
            ->orderBy('price_cents')
            ->first();

        return $record instanceof TariffRecord ? $this->mapper->toDomain($record) : null;
    }

    public function cheapestForCorridorAndClass(CorridorRef $corridor, VehicleClass $class): ?Tariff
    {
        $record = TariffRecord::query()
            ->where('corridor_from', $corridor->from)
            ->where('corridor_to', $corridor->to)
            ->where('corridor_type', $corridor->type)
            ->where('vehicle_class', $class->value)
            ->orderBy('price_cents')
            ->first();

        return $record instanceof TariffRecord ? $this->mapper->toDomain($record) : null;
    }
}
