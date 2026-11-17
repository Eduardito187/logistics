<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use Logistics\Shared\Domain\Enum\CaseInsensitiveEnum;

/**
 * Quién mueve un tramo del envío: la flota PROPIA de Dismac o un TERCERO (una transportadora).
 *
 * Solo describe el modo del acarreo; la DECISIÓN de propio vs. tercerizado (y el ruteo que la alimenta)
 * es de olas posteriores. Este value object es parte del cimiento del modelo de tramos (hub-and-spoke).
 */
enum HaulageMode: string
{
    use CaseInsensitiveEnum;

    /** Lo mueve la flota propia de Dismac (vehículo y tripulación de la operación). */
    case OwnFleet = 'own_fleet';

    /** Lo mueve una transportadora tercerizada (se vincula con `carrier_id`). */
    case ThirdParty = 'third_party';

    public function isOwnFleet(): bool
    {
        return $this === self::OwnFleet;
    }

    public function isThirdParty(): bool
    {
        return $this === self::ThirdParty;
    }
}
