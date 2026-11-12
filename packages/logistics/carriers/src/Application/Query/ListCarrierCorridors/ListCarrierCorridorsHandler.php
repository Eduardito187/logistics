<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\ListCarrierCorridors;

use Logistics\Carriers\Application\Contract\CarrierCorridorReader;
use Logistics\Carriers\Application\ReadModel\CarrierCorridorView;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Devuelve los corredores tarifados (distintos, con sus clases de vehículo y etiquetas legibles).
 *
 * Con `limit` (al paginar) el lector baja LIMIT/OFFSET a SQL y se devuelve una Page (ítems + total);
 * sin `limit`, la lista completa (paginación opt-in, como el resto de los listados de catálogo).
 *
 * @implements QueryHandler<ListCarrierCorridors, list<CarrierCorridorView>|Page<CarrierCorridorView>>
 */
final readonly class ListCarrierCorridorsHandler implements QueryHandler
{
    public function __construct(
        private CarrierCorridorReader $reader,
    ) {}

    /**
     * @return list<CarrierCorridorView>|Page<CarrierCorridorView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListCarrierCorridors);

        $corridors = $this->reader->corridors($query->carrierId, $query->limit, $query->offset);

        if ($query->limit === null) {
            return $corridors;
        }

        return new Page($corridors, $this->reader->count($query->carrierId));
    }
}
