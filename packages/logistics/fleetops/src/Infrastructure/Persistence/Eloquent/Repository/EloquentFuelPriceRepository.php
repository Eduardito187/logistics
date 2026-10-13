<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Repository;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Contract\FuelPriceRepository;
use Logistics\FleetOps\Domain\Model\FuelPrice;
use Logistics\FleetOps\Domain\ValueObject\FuelPriceId;
use Logistics\FleetOps\Domain\ValueObject\FuelType;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Mapper\FuelPriceMapper;
use Logistics\FleetOps\Infrastructure\Persistence\Eloquent\Model\FuelPriceRecord;

/**
 * Implementación Eloquent del repositorio de precios de combustible. `save` es un upsert por id.
 */
final readonly class EloquentFuelPriceRepository implements FuelPriceRepository
{
    public function __construct(
        private FuelPriceMapper $mapper = new FuelPriceMapper,
    ) {}

    public function nextIdentity(): FuelPriceId
    {
        return FuelPriceId::generate();
    }

    public function save(FuelPrice $price): void
    {
        $row = $this->mapper->toRow($price);
        FuelPriceRecord::query()->updateOrCreate(['id' => $row['id']], $row);
    }

    public function currentFor(FuelType $fuelType, DateTimeImmutable $at): ?FuelPrice
    {
        // Precio vigente = el más reciente cuya fecha de vigencia no supera la fecha consultada.
        $record = FuelPriceRecord::query()
            ->where('fuel_type', $fuelType->value)
            ->where('effective_from', '<=', $at->format('Y-m-d'))
            ->orderByDesc('effective_from')
            ->orderByDesc('created_at')
            ->first();

        return $record instanceof FuelPriceRecord ? $this->mapper->toDomain($record) : null;
    }

    public function all(): array
    {
        return array_values(FuelPriceRecord::query()
            ->orderByDesc('effective_from')
            ->get()
            ->map(fn (FuelPriceRecord $record): FuelPrice => $this->mapper->toDomain($record))
            ->all());
    }
}
