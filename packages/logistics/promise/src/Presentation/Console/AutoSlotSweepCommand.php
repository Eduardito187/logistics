<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Promise\Application\Command\AutoSlotPendingShipments\AutoSlotPendingShipments;
use Logistics\Promise\Application\Command\AutoSlotPendingShipments\AutoSlotPendingShipmentsHandler;

/**
 * Barrido de auto-slot (Módulo 1, Fase 1.1): slotea los envíos con fecha y sin ventana como red de
 * seguridad por si un reactor del outbox no llegó a hacerlo. Lo corre el scheduler (cada 10 min) o se
 * invoca a mano. No hace I/O por sí mismo: delega en el handler, que despacha un ReserveDeliveryDate por
 * envío vía el CommandBus. Imprime el resumen de la corrida para monitorearlo.
 */
final class AutoSlotSweepCommand extends Command
{
    protected $signature = 'logistics:promise:autoslot-sweep {--limit=500 : Máximo de envíos a slotear por corrida}';

    protected $description = 'Auto-assign a delivery window to shipments that have a date but no window yet';

    public function handle(AutoSlotPendingShipmentsHandler $handler): int
    {
        $handler->handle(new AutoSlotPendingShipments($this->limitOption()));

        $this->info(sprintf(
            'Auto-slot sweep finished: %d slotted, %d left unslotted.',
            $handler->lastSlotted(),
            $handler->lastUnslotted(),
        ));

        return self::SUCCESS;
    }

    /** Lee --limit saneado a un entero positivo, con el default del comando como piso. */
    private function limitOption(): int
    {
        $raw = $this->option('limit');
        $limit = is_numeric($raw) ? (int) $raw : 500;

        return $limit > 0 ? $limit : 500;
    }
}
