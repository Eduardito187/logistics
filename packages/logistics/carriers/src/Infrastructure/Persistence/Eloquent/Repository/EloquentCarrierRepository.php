<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Repository;

use Logistics\Carriers\Domain\Contract\CarrierRepository;
use Logistics\Carriers\Domain\Model\Carrier;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Mapper\CarrierMapper;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierRecord;

/**
 * Implementación Eloquent del repositorio de transportistas. `save` es un upsert por id.
 */
final readonly class EloquentCarrierRepository implements CarrierRepository
{
    public function __construct(
        private CarrierMapper $mapper = new CarrierMapper,
    ) {}

    public function nextIdentity(): CarrierId
    {
        return CarrierId::generate();
    }

    public function save(Carrier $carrier): void
    {
        $row = $this->mapper->toRow($carrier);
        CarrierRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function findById(CarrierId $id): ?Carrier
    {
        $record = CarrierRecord::query()->find($id->toString());

        return $record instanceof CarrierRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(CarrierRecord::query()
            ->orderBy('name')
            ->get()
            ->map(fn (CarrierRecord $record): Carrier => $this->mapper->toDomain($record))
            ->all());
    }
}
