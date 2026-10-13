<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

use Logistics\Telemetry\Domain\Exception\TelemetryException;

/**
 * Velocidad instantánea en km/h de una lectura de telemetría. No negativa; con un tope defensivo para
 * descartar lecturas GPS absurdas (ruido). Se usa para detectar movimiento vs inactividad y exceso de
 * velocidad.
 */
final readonly class Speed
{
    /** Tope defensivo: sobre esto una lectura GPS es ruido, no un camión. */
    private const MAX_KPH = 300.0;

    private function __construct(public float $kph) {}

    public static function kmh(float $kph): self
    {
        if ($kph < 0.0) {
            throw TelemetryException::because("La velocidad no puede ser negativa: {$kph}");
        }

        return new self(min($kph, self::MAX_KPH));
    }

    public static function zero(): self
    {
        return new self(0.0);
    }

    /**
     * ¿Está en movimiento? Por debajo del umbral se considera detenido (ruido GPS en reposo).
     */
    public function isMoving(float $thresholdKph = 3.0): bool
    {
        return $this->kph >= $thresholdKph;
    }

    public function exceeds(float $limitKph): bool
    {
        return $this->kph > $limitKph;
    }
}
