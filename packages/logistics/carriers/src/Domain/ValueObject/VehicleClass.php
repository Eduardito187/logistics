<?php

declare(strict_types=1);

namespace Logistics\Carriers\Domain\ValueObject;

/**
 * Clase de vehículo por la que se tarifa un corredor, para la consolidación entre departamentos.
 *
 * El corredor se mueve por tráiler, camión o transportista tercero según el volumen; la tarifa se
 * define por esta clase. El enum solo nombra las clases válidas; no decide cuál usar (eso es planning).
 */
enum VehicleClass: string
{
    /** Tráiler: mayor volumen, típicamente corredores CD→CD de alto flujo. */
    case Trailer = 'trailer';

    /** Camión: volumen medio. */
    case Truck = 'truck';

    /** Transportista tercero: volumen bajo o puntual subcontratado. */
    case ThirdParty = 'third_party';

    /**
     * Texto en inglés para el admin y la consola (regla 5 de CLAUDE.md).
     */
    public function label(): string
    {
        return match ($this) {
            self::Trailer => 'Trailer',
            self::Truck => 'Truck',
            self::ThirdParty => 'Third party',
        };
    }
}
