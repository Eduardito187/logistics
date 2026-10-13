<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Eloquent;

use Logistics\Tracking\Domain\Contract\TrackingRepository;
use Logistics\Tracking\Domain\Model\ShipmentTracking;
use Logistics\Tracking\Domain\ValueObject\TrackingId;
use Logistics\Tracking\Domain\ValueObject\TrackingToken;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Implementación Eloquent del repositorio de seguimiento.
 *
 * save() es un upsert por id del seguimiento; la unicidad de token y shipment_id se respalda en los
 * índices únicos de la tabla.
 */
final class EloquentTrackingRepository implements TrackingRepository
{
    public function __construct(
        private readonly TrackingMapper $mapper,
    ) {}

    public function nextIdentity(): TrackingId
    {
        return TrackingId::generate();
    }

    public function save(ShipmentTracking $tracking): void
    {
        TrackingRecord::query()->updateOrCreate(
            ['id' => $tracking->id()->toString()],
            $this->mapper->toRow($tracking),
        );
    }

    public function findById(TrackingId $id): ?ShipmentTracking
    {
        $record = TrackingRecord::query()->find($id->toString());

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByToken(TrackingToken $token): ?ShipmentTracking
    {
        $record = TrackingRecord::query()->where('token', $token->toString())->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function findByShipmentId(string $shipmentId): ?ShipmentTracking
    {
        $record = TrackingRecord::query()->where('shipment_id', $shipmentId)->first();

        return $record === null ? null : $this->mapper->toDomain($record);
    }

    public function stopsAheadOnRoute(string $routeId, int $position): array
    {
        $terminal = array_map(
            static fn (VisibleStatus $status): string => $status->value,
            array_filter(VisibleStatus::cases(), static fn (VisibleStatus $status): bool => $status->isTerminal()),
        );

        $records = TrackingRecord::query()
            ->where('route_id', $routeId)
            ->where('route_position', '>', $position)
            ->whereNotIn('status', $terminal)
            ->orderBy('route_position')
            ->get();

        return $records
            ->map(fn (TrackingRecord $record): ShipmentTracking => $this->mapper->toDomain($record))
            ->all();
    }
}
