<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\Query\ListSettlements;

use Logistics\Carriers\Application\ReadModel\SettlementView;
use Logistics\Carriers\Domain\Contract\SettlementRepository;
use Logistics\Carriers\Domain\Model\Settlement;
use Logistics\Carriers\Infrastructure\Persistence\Eloquent\Model\CarrierRecord;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;
use Logistics\Shared\Application\ReadModel\Page;

/**
 * Devuelve las liquidaciones (filtrables por transportista y estado) como proyecciones de lectura.
 *
 * Con `limit` (al paginar) baja LIMIT/OFFSET a SQL y devuelve una Page (ítems + total). Sin `limit`,
 * devuelve la lista completa, por compatibilidad. El nombre del transportista se resuelve EN LOTE solo
 * para los ítems de la página (evita N+1).
 *
 * @implements QueryHandler<ListSettlements, list<SettlementView>|Page<SettlementView>>
 */
final readonly class ListSettlementsHandler implements QueryHandler
{
    public function __construct(
        private SettlementRepository $settlements,
    ) {}

    /**
     * @return list<SettlementView>|Page<SettlementView>
     */
    public function handle(Query $query): array | Page
    {
        assert($query instanceof ListSettlements);

        $settlements = $this->settlements->list($query->carrierId, $query->status, $query->limit, $query->offset);

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

        $views = array_map(
            static fn (Settlement $settlement): SettlementView => SettlementView::fromDomain(
                $settlement,
                $carrierNames[$settlement->carrierId()] ?? null,
            ),
            $settlements,
        );

        if ($query->limit === null) {
            return $views;
        }

        return new Page($views, $this->settlements->countBy($query->carrierId, $query->status));
    }
}
