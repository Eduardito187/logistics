<?php

declare(strict_types=1);

namespace Commerce\Cart\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un carrito. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class CartId extends AbstractId {}
