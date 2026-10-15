<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un usuario. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class UserId extends AbstractId {}
