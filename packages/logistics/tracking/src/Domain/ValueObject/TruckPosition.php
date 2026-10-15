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

    /** Una posición se considera "en vivo" si se reportó dentro de esta ventana (segundos). */
    public const int LIVE_WINDOW_SECONDS = 300;

    public static function at(GeoPoint $point, DateTimeImmutable $reportedAt): self
    {
        return new self($point, $reportedAt);
    }

    /**
     * ¿La posición sigue siendo "en vivo" respecto de un instante dado? Una lectura más vieja que la
     * ventana (por defecto 5 min) se considera añeja: la UI la muestra como última posición conocida,
     * no como seguimiento en tiempo real. El instante lo provee el Clock de la app (nunca now() global).
     */
    public function isLiveAt(DateTimeImmutable $now, int $windowSeconds = self::LIVE_WINDOW_SECONDS): bool
    {
        $ageSeconds = $now->getTimestamp() - $this->reportedAt->getTimestamp();

        return $ageSeconds >= 0 && $ageSeconds <= $windowSeconds;
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
