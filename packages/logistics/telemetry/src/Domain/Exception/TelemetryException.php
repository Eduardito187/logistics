<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * Violación de una regla de negocio de telemetría: coordenada fuera de rango, velocidad negativa,
 * polígono de geocerca inválido, trip ya cerrado, alerta ya reconocida, etc.
 */
final class TelemetryException extends DomainException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }
}
