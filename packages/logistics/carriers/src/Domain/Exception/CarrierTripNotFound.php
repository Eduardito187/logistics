<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\Exception;

use Logistics\Shared\Domain\Exception\DomainException;

/**
 * No existe el viaje de transportista pedido. Se separa de CarriersException para que la API responda
 * 404 (recurso inexistente) en vez de 422 (regla de negocio violada).
 */
final class CarrierTripNotFound extends DomainException
{
    public static function withId(string $tripId): self
    {
        return new self("No existe el viaje de transportista {$tripId}.");
    }
}
