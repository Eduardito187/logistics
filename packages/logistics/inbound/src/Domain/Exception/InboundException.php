<?php

declare(strict_types=1);

namespace Logistics\Inbound\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de inbound: transición de estado inválida, ASN sin líneas, ventana de
 * cita mal formada, cantidad recibida negativa, etc.
 */
final class InboundException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
