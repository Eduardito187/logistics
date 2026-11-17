<?php

declare(strict_types=1);

namespace Logistics\Network\Domain\ValueObject;

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Modo de acarreo de un envío: la DECISIÓN que toma planning sobre quién hace el transporte.
 *
 *  - OwnFleet: lo mueve nuestra flota propia de punta a punta.
 *  - Outsourced: lo mueve una transportadora tercera (con un handoff corto de nuestra flota hasta su
 *    almacén).
 *
 * Ojo con la diferencia de vocabulario: este modo es la decisión a NIVEL ENVÍO (own_fleet|outsourced).
 * Cada TRAMO que arma el ruteo lleva su propio acarreo concreto 'own_fleet' o 'third_party'
 * (ver ItineraryPlanner): un envío tercerizado tiene un primer tramo own_fleet (el handoff) y un
 * segundo tramo third_party.
 */
enum HaulageMode: string
{
    use CaseInsensitiveEnum;

    /** Flota propia de punta a punta. */
    case OwnFleet = 'own_fleet';

    /** Transportadora tercera (con handoff de flota propia hasta su almacén). */
    case Outsourced = 'outsourced';

    /**
     * Acarreo concreto que lleva un tramo movido por nuestra flota.
     */
    public const string LEG_OWN_FLEET = 'own_fleet';

    /**
     * Acarreo concreto que lleva un tramo movido por un tercero.
     */
    public const string LEG_THIRD_PARTY = 'third_party';

    /**
     * Etiqueta legible en inglés para el admin y la consola.
     */
    public function label(): string
    {
        return match ($this) {
            self::OwnFleet => 'Own fleet',
            self::Outsourced => 'Outsourced',
        };
    }
}
