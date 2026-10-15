<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identificador tipado del horizonte activo de cupos de un municipio (o global). Por dentro es UUID v7.
 */
final readonly class ActiveHorizonId extends AbstractId {}
