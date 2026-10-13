<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Contract;

use Logistics\Planning\Domain\ValueObject\Eta;

/**
 * Parada ya resuelta por el motor de rutas: el envío con su ETA. El orden dentro de la lista devuelta
 * por el optimizador ES la secuencia de reparto propuesta.
 */
final readonly class OptimizedStop
{
    public function __construct(
        public string $shipmentId,
        public Eta $eta,
    ) {}
}
