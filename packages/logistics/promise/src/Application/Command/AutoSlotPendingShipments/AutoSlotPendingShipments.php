<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\AutoSlotPendingShipments;

use Logistics\Shared\Application\Command\Command;

/**
 * Barrido de auto-slot (Módulo 1, Fase 1.1): toma los envíos con fecha y sin ventana y les asigna una, como
 * red de seguridad por si un reactor del outbox no llegó a slotearlos. `$limit` acota el lote por corrida.
 *
 * No se despacha por el bus: lo invoca directo el comando de consola, igual que el barrido de expiración,
 * para que cada reserva corra en su propia transacción y una que falle no tumbe el resto del lote.
 */
final readonly class AutoSlotPendingShipments implements Command
{
    public function __construct(
        public int $limit = 500,
    ) {}
}
