<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identificador tipado de un municipio.
 *
 * Un municipio es un área administrativa (GeoArea de nivel Municipality); este id tipado existe para
 * que otros contextos (p. ej. capacity, que gestiona cupos por municipio) referencien el municipio sin
 * acoplarse al modelo de GeoArea ni a su persistencia. Es el valor que devuelve el MunicipalityLocator.
 */
final readonly class MunicipalityId extends AbstractId {}
