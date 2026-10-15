<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una liquidación de transportista. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class SettlementId extends AbstractId {}
