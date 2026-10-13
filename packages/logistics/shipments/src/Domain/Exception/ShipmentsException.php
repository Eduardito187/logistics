<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Error de regla de negocio del contexto de recepción de envíos: datos de ingreso inválidos o una
 * transición de estado no permitida.
 */
final class ShipmentsException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
