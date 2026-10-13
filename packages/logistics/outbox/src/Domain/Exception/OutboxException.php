<?php

declare(strict_types=1);

namespace Logistics\Outbox\Domain\Exception;

use Logistics\Outbox\Domain\ValueObject\OutboxStatus;
use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Error de regla de negocio del outbox: una transición de estado que no corresponde.
 *
 * Por ejemplo, intentar despachar un mensaje ya despachado o ya descartado (dead lettered).
 */
final class OutboxException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self("Outbox inválido: {$reason}");
    }

    public static function cannotTransition(OutboxStatus $from, string $to): self
    {
        return self::because("no se puede pasar de '{$from->value}' a '{$to}'");
    }
}
