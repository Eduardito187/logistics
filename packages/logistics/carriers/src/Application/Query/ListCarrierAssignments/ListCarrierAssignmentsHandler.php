<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\ListCarrierAssignments;

use Logistics\Carriers\Application\Contract\CarrierAssignmentReader;
use Logistics\Carriers\Application\ReadModel\CarrierAssignmentView;
use Logistics\Carriers\Domain\Exception\CarriersException;
use Logistics\Carriers\Domain\ValueObject\CarrierTripStatus;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Página de viajes asignados con su carga, recortada en SQL (LIMIT/OFFSET + COUNT aparte) por el lector.
 *
 * @implements QueryHandler<ListCarrierAssignments, Page<CarrierAssignmentView>>
 */
final readonly class ListCarrierAssignmentsHandler implements QueryHandler
{
    public function __construct(
        private CarrierAssignmentReader $reader,
    ) {}

    /**
     * @return Page<CarrierAssignmentView>
     */
    public function handle(Query $query): Page
    {
        assert($query instanceof ListCarrierAssignments);

        $statuses = $this->statuses($query->statuses);

        return new Page(
            $this->reader->assignments($query->carrierId, $statuses, $query->limit, $query->offset),
            $this->reader->count($query->carrierId, $statuses),
        );
    }

    /**
     * Valida los estados pedidos; vacío → los vigentes.
     *
     * @param  list<string> $statuses
     * @return list<string>
     */
    private function statuses(array $statuses): array
    {
        if ($statuses === []) {
            return CarrierTripStatus::activeValues();
        }

        $out = [];
        foreach ($statuses as $status) {
            if (CarrierTripStatus::tryFrom($status) === null) {
                throw CarriersException::because("Estado de viaje inválido: {$status}.");
            }
            $out[] = $status;
        }

        return array_values(array_unique($out));
    }
}
