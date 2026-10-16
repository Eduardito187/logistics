<?php

declare(strict_types=1);

namespace Logistics\Returns\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de returns: transición de estado inválida, devolución sin líneas,
 * disposición incompatible con el tipo, cantidad negativa, etc.
 */
final class ReturnsException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
