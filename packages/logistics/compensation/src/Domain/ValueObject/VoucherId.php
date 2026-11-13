<?php

declare(strict_types=1);

namespace Logistics\Compensation\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Id tipado de un vale. UUID v7 por dentro.
 */
final readonly class VoucherId extends AbstractId {}
