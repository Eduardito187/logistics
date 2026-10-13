<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se calificó a un transportista; incluye el nuevo promedio y la cantidad acumulada de calificaciones.
 */
final readonly class CarrierRated extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public int $rating,
        public float $averageRating,
        public int $ratingCount,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'carriers.carrier_rated';
    }

    public function payload(): array
    {
        return [
            'rating' => $this->rating,
            'average_rating' => $this->averageRating,
            'rating_count' => $this->ratingCount,
        ];
    }
}
