<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

/**
 * Nivel de confianza de una dirección, derivado del score por umbrales. Resume el puntaje para que
 * la operación lo entienda de un vistazo: una dirección "Low" es candidata a confirmarse con el
 * cliente antes de salir a ruta (KPI del piloto: llamadas del chofer por ubicación).
 */
enum AddressConfidenceLevel: string
{
    case High = 'HIGH';

    case Medium = 'MEDIUM';

    case Low = 'LOW';

    /** A partir de este score (inclusive) la dirección se considera de confianza alta. */
    public const HIGH_THRESHOLD = 70;

    /** A partir de este score (inclusive) la dirección se considera de confianza media. */
    public const MEDIUM_THRESHOLD = 40;

    /**
     * Deriva el nivel a partir del score 0..100 según los umbrales.
     */
    public static function fromScore(int $score): self
    {
        return match (true) {
            $score >= self::HIGH_THRESHOLD => self::High,
            $score >= self::MEDIUM_THRESHOLD => self::Medium,
            default => self::Low,
        };
    }
}
