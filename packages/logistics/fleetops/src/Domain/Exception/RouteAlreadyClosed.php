<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * La ruta ya tiene su lectura de odómetro de LLEGADA: una ruta se cierra una sola vez. Es un conflicto con
 * el estado actual (HTTP 409), no un dato inválido.
 */
final class RouteAlreadyClosed extends DomainException
{
    public static function forRoute(string $routeId): self
    {
        return new self("La ruta {$routeId} ya registró su odómetro de llegada: no se puede cerrar dos veces.");
    }
}
