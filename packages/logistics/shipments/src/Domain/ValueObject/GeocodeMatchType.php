<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

/**
 * Tipo de coincidencia de una geocodificación: qué tan fina es la ubicación que el proveedor devolvió.
 *
 * Es el criterio de dominio detrás del score de confianza: una coincidencia EXACTA (sobre el predio)
 * merece confianza alta; una APROXIMADA (centro de manzana o localidad) es candidata a confirmarse con
 * el cliente antes de salir a ruta. Cada proveedor (Google, Nominatim) traduce sus señales propias a
 * uno de estos tipos; el dominio solo conoce este vocabulario, no al proveedor.
 */
enum GeocodeMatchType: string
{
    /** Coincidencia exacta sobre el predio/dirección (rooftop, house number). */
    case Exact = 'EXACT';

    /** Interpolada entre dos puntos conocidos de la calle (número estimado sobre la vía). */
    case Interpolated = 'INTERPOLATED';

    /** Aproximada: centro de manzana, barrio o localidad. */
    case Approximate = 'APPROXIMATE';

    /** Sin coincidencia: el proveedor no ubicó la dirección. */
    case None = 'NONE';
}
