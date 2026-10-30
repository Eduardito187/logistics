<?php

declare(strict_types=1);

namespace Commerce\Promotions\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una promoción. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class PromotionId extends AbstractId {}
