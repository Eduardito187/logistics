<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identificador tipado de un bucket de cupo.
 *
 * Hereda generate/fromString/fromUuid/equals de AbstractId; existe solo para que el type system
 * distinga un CapacityBucketId del id de cualquier otro agregado. Por dentro es un UUID v7.
 */
final readonly class CapacityBucketId extends AbstractId {}
