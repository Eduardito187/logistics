<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Error de regla de negocio del contexto Cobertura: geometría inválida, GeoJSON malformado,
 * zona inexistente, etc.
 */
final class CoverageException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self("Cobertura inválida: {$reason}");
    }
}
