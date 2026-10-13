<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identificador tipado de un área administrativa (departamento, provincia, municipio, ciudad…).
 */
final readonly class GeoAreaId extends AbstractId {}
