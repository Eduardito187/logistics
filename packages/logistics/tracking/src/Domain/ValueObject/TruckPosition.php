<?php

declare(strict_types=1);

namespace Logistics\Tracking\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Tracking\Domain\Exception\TrackingException;

/**
 * Posición en vivo del camión: un punto geográfico más el instante en que se reportó y, opcionalmente, la
 * cinemática del reporte GPS (velocidad en m/s, rumbo en grados 0..360 y precisión horizontal en metros).
 *
 * El timestamp permite descartar lecturas viejas y mostrar "hace N minutos" en la página pública. La
 * cinemática es OPCIONAL (el dispositivo la manda cuando la tiene); cuando falta velocidad o rumbo se
 * derivan de dos posiciones seguidas con {@see deriveKinematicsFrom()}, dato puro sin I/O.
 */
final readonly class TruckPosition
{
    private function __construct(
        public GeoPoint $point,
        public DateTimeImmutable $reportedAt,
        public ?float $speedMps,
        public ?float $headingDeg,
        public ?float $accuracyM,
    ) {}

    /** Una posición se considera "en vivo" si se reportó dentro de esta ventana (segundos). */
    public const int LIVE_WINDOW_SECONDS = 300;

    public static function at(
        GeoPoint $point,
        DateTimeImmutable $reportedAt,
        ?float $speedMps = null,
        ?float $headingDeg = null,
        ?float $accuracyM = null,
    ): self {
        if ($speedMps !== null && $speedMps < 0.0) {
            throw TrackingException::because("la velocidad no puede ser negativa: {$speedMps}");
        }
        if ($headingDeg !== null && ($headingDeg < 0.0 || $headingDeg > 360.0)) {
            throw TrackingException::because("el rumbo debe estar entre 0 y 360: {$headingDeg}");
        }
        if ($accuracyM !== null && $accuracyM < 0.0) {
            throw TrackingException::because("la precisión no puede ser negativa: {$accuracyM}");
        }

        // Normaliza 360 a 0 (mismo rumbo) para que el valor guardado quede en [0, 360).
        $heading = $headingDeg !== null ? fmod($headingDeg, 360.0) : null;

        return new self($point, $reportedAt, $speedMps, $heading, $accuracyM);
    }

    /**
     * Devuelve una posición con la velocidad y el rumbo DERIVADOS de la posición anterior cuando no vinieron
     * en el reporte (dos lecturas seguidas: velocidad = distancia/tiempo, rumbo = acimut entre ambas). No
     * pisa los valores que sí llegaron. Si no hay anterior, o el tiempo no avanzó, deja lo que había.
     */
    public function deriveKinematicsFrom(?self $previous): self
    {
        if ($previous === null) {
            return $this;
        }

        $seconds = $this->reportedAt->getTimestamp() - $previous->reportedAt->getTimestamp();
        if ($seconds <= 0) {
            return $this;
        }

        $distance = $previous->point->distanceMetersTo($this->point);
        $speed = $this->speedMps ?? $distance / $seconds;
        // El rumbo solo tiene sentido si el camión se movió; parado se conserva el anterior (o null).
        $heading = $this->headingDeg
            ?? ($distance >= 1.0 ? $previous->point->bearingDegreesTo($this->point) : $previous->headingDeg);

        return new self($this->point, $this->reportedAt, $speed, $heading, $this->accuracyM);
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
            && $this->reportedAt->getTimestamp() === $other->reportedAt->getTimestamp()
            && $this->speedMps === $other->speedMps
            && $this->headingDeg === $other->headingDeg
            && $this->accuracyM === $other->accuracyM;
    }

    /**
     * Forma pública de la posición (la que consume la página de seguimiento): solo coordenadas e instante.
     * La cinemática (velocidad/rumbo/precisión) se persiste desde las propiedades del VO, no por acá.
     *
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
