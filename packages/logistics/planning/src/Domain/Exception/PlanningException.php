<?php

declare(strict_types=1);

namespace Logistics\Planning\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Error de regla de negocio del contexto de planificación: datos de plan inválidos o una transición de
 * estado no permitida (p. ej. publicar un plan sin proponer, o reordenar uno ya publicado).
 */
final class PlanningException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
