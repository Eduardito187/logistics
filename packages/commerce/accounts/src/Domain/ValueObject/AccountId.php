<?php

declare(strict_types=1);

namespace Commerce\Accounts\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una cuenta comercial. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class AccountId extends AbstractId {}
