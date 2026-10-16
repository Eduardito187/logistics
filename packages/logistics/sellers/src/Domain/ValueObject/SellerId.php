<?php

declare(strict_types=1);

namespace Logistics\Sellers\Domain\ValueObject;

use Logistics\Shared\Domain\ValueObject\AbstractId;

/**
 * Identidad tipada de un seller. Por dentro es un UUID v7 (ver AbstractId).
 *
 * Es el id que la dimensión de propiedad (Logistics\Shared\Domain\ValueObject\Ownership) referencia
 * como `ownerPartyId` desde los contextos aguas abajo (shipments, inbound, inventory, settlement).
 */
final readonly class SellerId extends AbstractId {}
