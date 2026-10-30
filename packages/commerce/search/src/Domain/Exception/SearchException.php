<?php

declare(strict_types=1);

namespace Commerce\Search\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de un criterio de búsqueda: límite fuera de rango o estado no permitido.
 */
final class SearchException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
