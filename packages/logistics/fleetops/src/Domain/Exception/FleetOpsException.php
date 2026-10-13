<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de operación/costos de flota: monto o litros negativos, odómetro
 * que retrocede, orden de mantenimiento ya cerrada, salida ya retornada, moneda distinta al sumar, etc.
 */
final class FleetOpsException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
