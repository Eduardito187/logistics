<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\ListSettlements;

use Logistics\Carriers\Application\ReadModel\SettlementView;
use Logistics\Carriers\Domain\Contract\SettlementRepository;
use Logistics\Carriers\Domain\Model\Settlement;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierRecord;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Devuelve las liquidaciones (filtrables por transportista y estado) como proyecciones de lectura.
 *
 * @implements QueryHandler<ListSettlements, list<SettlementView>>
 */
final readonly class ListSettlementsHandler implements QueryHandler
{
    public function __construct(
        private SettlementRepository $settlements,
    ) {}

    /**
     * @return list<SettlementView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListSettlements);

        $settlements = $this->settlements->list($query->carrierId, $query->status);

        // Resolución en lote de los nombres legibles de transportista para evitar N+1 en el listado.
        $carrierIds = array_values(array_unique(array_map(
            static fn (Settlement $settlement): string => $settlement->carrierId(),
            $settlements,
        )));

        /** @var array<string, string> $carrierNames */
        $carrierNames = [];
        if ($carrierIds !== []) {
            foreach (CarrierRecord::query()->whereIn('id', $carrierIds)->get() as $carrier) {
                $carrierNames[$carrier->id] = $carrier->name;
            }
        }

        return array_map(
            static fn (Settlement $settlement): SettlementView => SettlementView::fromDomain(
                $settlement,
                $carrierNames[$settlement->carrierId()] ?? null,
            ),
            $settlements,
        );
    }
}
