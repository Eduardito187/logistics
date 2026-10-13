<?php

declare(strict_types=1);

namespace Logistics\Coverage\Application\Command;

use Logistics\Coverage\Domain\ValueObject\CoverageFormat;
use Logistics\Shared\Application\Command\Command;

/**
 * Intención de importar el mapa de cobertura de un departamento desde un archivo KML o GeoJSON, de
 * forma IDEMPOTENTE: dos importaciones del mismo mapa no duplican zonas ni el departamento.
 *
 * El departamento se identifica por nombre (clave estable de la jerarquía): el handler lo da de alta
 * si no existe y cuelga de él las zonas del archivo. El parseo del contenido lo hace el servicio de
 * dominio correspondiente al formato; el resultado (altas/actualizaciones/errores) se recoge en el
 * ImportCoverageReport.
 */
final readonly class ImportCoverage implements Command
{
    public function __construct(
        public string $departmentName,
        public string $content,
        public CoverageFormat $format,
    ) {}
}
