<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Query\ListFailedTransfers;

use Logistics\SapConnector\Application\ReadModel\StockTransferView;
use Logistics\SapConnector\Domain\Contract\StockTransferRepository;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve los traspasos fallidos como read models, para el panel de reconciliación.
 *
 * @implements QueryHandler<ListFailedTransfers, list<StockTransferView>>
 */
final class ListFailedTransfersHandler implements QueryHandler
{
    public function __construct(
        private readonly StockTransferRepository $transfers,
    ) {}

    /**
     * @return list<StockTransferView>
     */
    public function handle(Query $query): array
    {
        if (!$query instanceof ListFailedTransfers) {
            throw SapConnectorException::because('ListFailedTransfersHandler solo maneja ListFailedTransfers');
        }

        return array_map(
            static fn (StockTransfer $transfer): StockTransferView => StockTransferView::fromTransfer($transfer),
            $this->transfers->failed(),
        );
    }
}
