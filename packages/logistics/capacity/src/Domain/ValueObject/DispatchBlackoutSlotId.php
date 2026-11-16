<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identificador tipado de una franja horaria bloqueada de despacho. Por dentro es un UUID v7.
 */
final readonly class DispatchBlackoutSlotId extends AbstractId {}
