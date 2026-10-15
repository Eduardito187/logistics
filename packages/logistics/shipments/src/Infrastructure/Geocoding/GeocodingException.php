<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Geocoding;

use RuntimeException;

/**
 * Falla de infraestructura al geocodificar (transporte, respuesta inválida o cuota agotada).
 *
 * Es un problema del adaptador, no del dominio: se propaga para que el worker reintente (el comando
 * de geocodificación corre vía outbox, con reintentos), no para abortar la operación del usuario.
 */
final class GeocodingException extends RuntimeException
{
    public static function because(string $reason): self
    {
        return new self($reason);
    }

    public static function quotaExceeded(int $quota): self
    {
        return new self("cuota diaria de geocodificación agotada ({$quota} requests)");
    }
}
