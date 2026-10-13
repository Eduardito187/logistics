<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

use DateTimeImmutable;

/**
 * Posición en vivo del camión: un punto geográfico más el instante en que se reportó.
 *
 * El timestamp permite descartar lecturas viejas y mostrar "hace N minutos" en la página pública.
 */
final readonly class TruckPosition
{
    private function __construct(
        public GeoPoint $point,
        public DateTimeImmutable $reportedAt,
    ) {}

    public static function at(GeoPoint $point, DateTimeImmutable $reportedAt): self
    {
        return new self($point, $reportedAt);
    }

    public function equals(self $other): bool
    {
        return $this->point->equals($other->point)
            && $this->reportedAt->getTimestamp() === $other->reportedAt->getTimestamp();
    }

    /**
     * @return array{lat: float, lng: float, reported_at: string}
     */
    public function toArray(): array
    {
        return [
            'lat' => $this->point->latitude,
            'lng' => $this->point->longitude,
            'reported_at' => $this->reportedAt->format(DateTimeImmutable::ATOM),
        ];
    }
}
