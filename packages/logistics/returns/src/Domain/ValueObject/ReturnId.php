<?php

declare(strict_types=1);

namespace Logistics\Returns\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una devolución (ReturnOrder). Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class ReturnId extends AbstractId {}
