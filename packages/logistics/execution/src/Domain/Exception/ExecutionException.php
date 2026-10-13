<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Error de regla de negocio del contexto de ejecución: datos de escaneo inválidos o una transición de
 * estado de la entrega no permitida.
 */
final class ExecutionException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
