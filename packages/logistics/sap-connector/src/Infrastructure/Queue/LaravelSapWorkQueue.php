<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Infrastructure\Queue;

use Logistics\SapConnector\Application\Contract\SapWorkQueue;
use Logistics\SapConnector\Domain\ValueObject\SapNotificationId;

/**
 * Adaptador del puerto SapWorkQueue sobre la cola de Laravel: todo va a la cola dedicada (`sap` por
 * defecto, config `logistics-sap-connector.queue`), consumida por sus propios workers.
 *
 * Se encola por PendingDispatch (`Job::dispatch()`) para que se respete ShouldBeUnique (la corrida de
 * reconciliación y la importación no se apilan) y `afterCommit`: si el pedido nace dentro de una
 * transacción (p. ej. el CommandBus), el job sale recién al confirmarse, así nunca lee un estado que
 * todavía no se guardó.
 */
final readonly class LaravelSapWorkQueue implements SapWorkQueue
{
    public function __construct(
        private string $queue = 'sap',
    ) {}

    public function queueNotification(SapNotificationId $id): void
    {
        SendSapNotificationJob::dispatch($id->toString())->onQueue($this->queue)->afterCommit();
    }

    public function queueTransferDispatch(): void
    {
        DispatchStockTransfersJob::dispatch()->onQueue($this->queue)->afterCommit();
    }

    public function queueOrdersImport(): void
    {
        ImportSapOrdersJob::dispatch()->onQueue($this->queue)->afterCommit();
    }
}
