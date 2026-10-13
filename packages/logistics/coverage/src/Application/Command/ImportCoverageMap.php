<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Command;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de importar el mapa de cobertura actual desde un GeoJSON FeatureCollection.
 */
final readonly class ImportCoverageMap implements Command
{
    /**
     * @param array<string, mixed> $featureCollection FeatureCollection de GeoJSON ya decodificado
     */
    public function __construct(public array $featureCollection) {}
}
