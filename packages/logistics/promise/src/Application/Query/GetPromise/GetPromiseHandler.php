<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Query\GetPromise;

use Logistics\Coverage\Infrastructure\Persistence\Eloquent\ZoneRecord;
use Logistics\Promise\Application\ReadModel\PromiseView;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * @implements QueryHandler<GetPromise, ?PromiseView>
 */
final class GetPromiseHandler implements QueryHandler
{
    public function __construct(
        private readonly PromiseRepository $promises,
    ) {}

    public function handle(Query $query): ?PromiseView
    {
        if (!$query instanceof GetPromise) {
            throw PromiseException::because('GetPromiseHandler solo maneja GetPromise');
        }

        $promise = $this->promises->findById(PromiseId::fromString($query->promiseId));

        if ($promise === null) {
            return null;
        }

        // El nombre legible de la zona pertenece al contexto coverage. Promise está desacoplado de
        // coverage (solo conoce el id de zona), pero para no exponer un UUID crudo en API/panel se
        // resuelve aquí, de forma puntual y de solo lectura, el nombre desde ZoneRecord. Null si la
        // zona no existe.
        $zoneName = ZoneRecord::query()->whereKey($promise->zoneId())->value('name');

        return PromiseView::fromPromise($promise, $zoneName === null ? null : (string) $zoneName);
    }
}
