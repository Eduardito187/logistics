<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una liquidación de seller. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class SettlementId extends AbstractId {}
