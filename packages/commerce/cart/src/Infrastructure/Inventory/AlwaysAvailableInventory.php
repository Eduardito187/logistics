<?php

declare(strict_types=1);

namespace Commerce\Cart\Infrastructure\Inventory;

use Commerce\Cart\Domain\Contract\InventoryAvailabilityPort;
use Commerce\Cart\Domain\ValueObject\Sku;

/**
 * Adaptador STUB del puerto de disponibilidad: siempre responde que hay stock.
 *
 * Es un marcador de posición deliberado para la Fase 0 del carrito: todavía no existe el cableado real
 * a `inventory`/ATP (ADR 018). En una fase posterior se reemplaza por un adaptador que consulte el read
 * model de disponibilidad de `inventory` (anti-corrupción), sin cambiar el Domain ni la Application del
 * carrito: solo se re-bindea el puerto en el ServiceProvider.
 */
final class AlwaysAvailableInventory implements InventoryAvailabilityPort
{
    public function isAvailable(Sku $sku, int $qty): bool
    {
        return true;
    }
}
