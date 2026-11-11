<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Query\ListAvailablePrinters;

use Logistics\Labels\Application\Contract\PrinterReader;
use Logistics\Labels\Application\ReadModel\PrinterView;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Lee las impresoras activas visibles para el alcance pedido (ver {@see ListAvailablePrinters}).
 *
 * @implements QueryHandler<ListAvailablePrinters, list<PrinterView>>
 */
final readonly class ListAvailablePrintersHandler implements QueryHandler
{
    public function __construct(
        private PrinterReader $reader,
    ) {}

    /**
     * @return list<PrinterView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListAvailablePrinters);

        return $this->reader->available($query->allNodes ? null : $query->nodeId, $query->allNodes);
    }
}
