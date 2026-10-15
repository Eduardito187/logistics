<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de una alerta de telemetría. Por dentro es un UUID v7 (ver AbstractId).
 */
final readonly class AlertId extends AbstractId {}
