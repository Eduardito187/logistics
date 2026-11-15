<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * La salida (ruta) ya tiene su inspección pre-salida válida: cada salida se inspecciona una sola vez. Es
 * un conflicto con el estado actual (HTTP 409), no un dato inválido. Una inspección con falla crítica no
 * cuenta: tras la reparación el chofer puede volver a inspeccionar la misma salida.
 */
final class RouteAlreadyInspected extends DomainException
{
    public static function forRoute(string $routeId): self
    {
        return new self("La ruta {$routeId} ya tiene su inspección pre-salida: cada salida se inspecciona una sola vez.");
    }
}
