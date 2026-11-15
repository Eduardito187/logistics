<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * La ruta ya tiene su lectura de odómetro de SALIDA: cada salida (ruta) registra una sola. Simétrico de
 * RouteAlreadyClosed para la llegada. Es un conflicto con el estado actual (HTTP 409), no un dato inválido.
 */
final class DepartureAlreadyRecorded extends DomainException
{
    public static function forRoute(string $routeId): self
    {
        return new self("La ruta {$routeId} ya registró su odómetro de salida: cada salida se registra una sola vez.");
    }
}
