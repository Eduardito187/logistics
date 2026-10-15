<?php

declare(strict_types=1);

namespace Logistics\Shipments\Presentation\Console;

use Illuminate\Console\Command;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shipments\Application\Contract\MagentoOrderReader;
use Throwable;

/**
 * Trae los pedidos pendientes de Magento y los ingresa por la API ÚNICA de recepción (SHP-07).
 *
 * Presentation habla con Application: lee vía el contrato MagentoOrderReader y despacha cada pedido
 * como ReceiveShipment por el CommandBus (transacción + outbox por pedido). Idempotente: reconsultar
 * Magento no duplica envíos. Pensado para el scheduler. Sin credenciales, el lector stub no trae nada.
 */
final class PullMagentoOrdersCommand extends Command
{
    protected $signature = 'logistics:shipments:pull-magento';

    protected $description = 'Pull pending orders from Magento and receive them into the platform';

    public function handle(MagentoOrderReader $reader, CommandBus $commandBus): int
    {
        $orders = $reader->fetchPendingOrders();

        if ($orders === []) {
            $this->info('No pending Magento orders to receive.');

            return self::SUCCESS;
        }

        $received = 0;
        $failed = 0;
        foreach ($orders as $command) {
            try {
                $commandBus->dispatch($command);
                $received++;
            } catch (Throwable $exception) {
                $failed++;
                $this->warn("Order {$command->externalId} failed: ".$exception->getMessage());
            }
        }

        $this->info("Magento pull done: {$received} received, {$failed} failed.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
