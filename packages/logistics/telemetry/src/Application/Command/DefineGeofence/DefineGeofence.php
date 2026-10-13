<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Command\DefineGeofence;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de definir una geocerca nueva.
 *
 * El polígono llega como lista de pares {lat,lng} para no acoplar la entrada al dominio; el handler lo
 * convierte al VO Polygon.
 */
final readonly class DefineGeofence implements Command
{
    /**
     * @param list<array{lat: float, lng: float}> $polygon
     */
    public function __construct(
        public string $name,
        public string $type,
        public array $polygon,
        public ?string $nodeRef = null,
    ) {}
}
