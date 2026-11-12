<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\RequestStockTransferDispatch;

use Logistics\SapConnector\Application\Contract\SapWorkQueue;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Encola la corrida de reconciliación en la cola `sap`. El request (o la acción Filament) responde al
 * instante; el HTTP a SAP corre en el worker, fuera de la transacción del bus y sin tope de request.
 *
 * @implements CommandHandler<RequestStockTransferDispatch>
 */
final readonly class RequestStockTransferDispatchHandler implements CommandHandler
{
    public function __construct(
        private SapWorkQueue $queue,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RequestStockTransferDispatch) {
            throw SapConnectorException::because('RequestStockTransferDispatchHandler solo maneja RequestStockTransferDispatch');
        }

        $this->queue->queueTransferDispatch();
    }
}
