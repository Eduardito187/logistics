<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un motivo de entrega fallida del catálogo configurable. Por dentro es un UUID v7.
 */
final readonly class FailureReasonId extends AbstractId {}
