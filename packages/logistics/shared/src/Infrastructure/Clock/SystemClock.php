<?php

declare(strict_types=1);

namespace Logistics\Shared\Infrastructure\Clock;

use DateTimeImmutable;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Reloj real. En tests se reemplaza por uno fijo para controlar el tiempo.
 */
final class SystemClock implements Clock
{
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable();
    }
}
