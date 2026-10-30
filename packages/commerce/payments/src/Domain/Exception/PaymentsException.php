<?php

declare(strict_types=1);

namespace Commerce\Payments\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de pagos: pago inexistente, transición de estado inválida, monto
 * negativo, moneda distinta al operar montos, método/estado desconocido, etc.
 */
final class PaymentsException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
