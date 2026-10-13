<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un ayudante. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class HelperId extends AbstractId {}
