<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Domain\ValueObject;

/**
 * Origen de una lectura de telemetría: de dónde llegó el ping a la plataforma.
 */
enum PingSource: string
{
    /** Desde la app del conductor (Flutter): GPS del teléfono. */
    case App = 'app';

    /** Desde una integración externa (telemetría de flota, GPS embarcado del vehículo). */
    case Integration = 'integration';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::App => 'Driver app',
            self::Integration => 'Integration',
        };
    }
}
