<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Command\RequestSapOrdersImport;

use Logistics\SapConnector\Application\Contract\SapWorkQueue;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Encola la lectura de pedidos de SAP en la cola `sap`; el worker corre ImportSapOrders fuera del request.
 *
 * @implements CommandHandler<RequestSapOrdersImport>
 */
final readonly class RequestSapOrdersImportHandler implements CommandHandler
{
    public function __construct(
        private SapWorkQueue $queue,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RequestSapOrdersImport) {
            throw SapConnectorException::because('RequestSapOrdersImportHandler solo maneja RequestSapOrdersImport');
        }

        $this->queue->queueOrdersImport();
    }
}
