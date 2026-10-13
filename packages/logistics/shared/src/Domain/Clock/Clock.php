<?php

declare(strict_types=1);

namespace Logistics\Shared\Domain\Clock;

use DateTimeImmutable;

/**
 * El dominio pide la hora por acá, nunca con now() global: así los tests fijan el tiempo.
 */
interface Clock
{
    public function now(): DateTimeImmutable;
}
