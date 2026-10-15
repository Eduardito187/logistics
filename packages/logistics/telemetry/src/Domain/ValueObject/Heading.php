<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

use Logistics\Telemetry\Domain\Exception\TelemetryException;

/**
 * Rumbo (bearing) en grados [0,360) de una lectura de telemetría: 0 = norte, 90 = este. Opcional en los
 * pings; cuando viene, permite dibujar la orientación del camión en el mapa en vivo.
 */
final readonly class Heading
{
    private function __construct(public int $degrees) {}

    public static function degrees(int $degrees): self
    {
        if ($degrees < 0 || $degrees >= 360) {
            throw TelemetryException::because("Rumbo fuera de rango [0,360): {$degrees}");
        }

        return new self($degrees);
    }
}
