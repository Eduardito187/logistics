<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\Query\GetDispatchLines;

use Logistics\Labels\Application\Contract\DispatchLinesReader;
use Logistics\Labels\Application\ReadModel\DispatchLinesView;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Resuelve las líneas de despacho de una orden delegando en el {@see DispatchLinesReader} (lectura
 * cross-context tolerante). Solo lectura; nombres humanos, cero uuid visible.
 *
 * @implements QueryHandler<GetDispatchLines, DispatchLinesView>
 */
final readonly class GetDispatchLinesHandler implements QueryHandler
{
    public function __construct(
        private DispatchLinesReader $reader,
    ) {}

    public function handle(Query $query): DispatchLinesView
    {
        assert($query instanceof GetDispatchLines);

        return $this->reader->read($query->orderNumber);
    }
}
