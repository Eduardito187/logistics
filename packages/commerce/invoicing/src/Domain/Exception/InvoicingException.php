<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de facturación: factura inexistente, transición de estado inválida,
 * factura sin líneas, monto negativo, moneda vacía, etc.
 */
final class InvoicingException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
