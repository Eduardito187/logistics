<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Command;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de importar el mapa de cobertura actual desde un documento KML (texto XML crudo).
 *
 * El parseo del XML lo hace el servicio de dominio KmlCoverageParser dentro del handler.
 */
final readonly class ImportCoverageKml implements Command
{
    public function __construct(public string $kml) {}
}
