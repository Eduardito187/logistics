<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * El camión ya tiene OTRA tripulación ese día con otro chofer: un camión lleva una sola tripulación por
 * día. Es un conflicto con el estado actual (HTTP 409), no un dato inválido; se resuelve pidiendo el
 * reemplazo explícito (`replace`). El mensaje nombra la placa y el chofer, nunca identificadores.
 */
final class CrewConflict extends DomainException
{
    public static function vehicleTaken(string $plate, string $driverName, string $date): self
    {
        return new self(
            "El camión {$plate} ya está asignado a {$driverName} para el {$date}. "
            .'Para quitárselo y asignarlo a este chofer, confirmá el reemplazo.',
        );
    }

    public static function concurrentAssignment(): self
    {
        return new self(
            'Otra asignación para ese chofer o ese camión se registró al mismo tiempo. Revisá la tripulación del día y volvé a intentar.',
        );
    }
}
