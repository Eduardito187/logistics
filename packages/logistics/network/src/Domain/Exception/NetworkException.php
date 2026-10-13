<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Error de regla de negocio de la red logística: coordenadas fuera de rango, código mal formado,
 * transición de estado inválida, etc.
 */
final class NetworkException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self("Error de red logística: {$reason}");
    }
}
