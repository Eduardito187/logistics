<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de flota: placa mal formada, capacidad negativa, tripulación sin
 * chofer, vehículo inexistente al cambiar su estado, etc.
 */
final class FleetException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
