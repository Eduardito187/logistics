<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una tarifa. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class TariffId extends AbstractId {}
