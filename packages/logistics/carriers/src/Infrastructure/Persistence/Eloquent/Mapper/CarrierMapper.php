<?php

declare(strict_types=1);

namespace Logistics\Carriers\Infrastructure\Persistence\Eloquent\Mapper;

use Logistics\Carriers\Domain\Model\Carrier;
use Logistics\Carriers\Domain\ValueObject\CarrierId;
use Logistics\Carriers\Domain\ValueObject\CarrierStatus;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierRecord;

/**
 * Traduce entre el agregado Carrier (dominio) y su fila Eloquent. Único punto que conoce ambos.
 */
final class CarrierMapper
{
    public function toDomain(CarrierRecord $record): Carrier
    {
        return Carrier::reconstitute(
            CarrierId::fromString($record->id),
            $record->name,
            $record->tax_id,
            CarrierStatus::from($record->status),
            $record->rating,
            $record->rating_count,
            array_values($record->documents),
        );
    }

    /**
     * @return array{id: string, name: string, tax_id: string, status: string, rating: float|null, rating_count: int, documents: list<array{type: string, reference: string}>}
     */
    public function toRow(Carrier $carrier): array
    {
        return [
            'id' => $carrier->id()->toString(),
            'name' => $carrier->name(),
            'tax_id' => $carrier->taxId(),
            'status' => $carrier->status()->value,
            'rating' => $carrier->averageRating(),
            'rating_count' => $carrier->ratingCount(),
            'documents' => array_values($carrier->documents()),
        ];
    }
}
