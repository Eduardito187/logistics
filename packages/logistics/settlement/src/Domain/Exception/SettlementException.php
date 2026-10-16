<?php

declare(strict_types=1);

namespace Logistics\Settlement\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de liquidación de sellers: periodo mal formado, transición de estado
 * inválida, moneda distinta al operar montos, fees que superan el bruto, etc.
 */
final class SettlementException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
