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
 * Devuelve las liquidaciones (filtrables por transportista, estado y rango de período) como proyecciones
 * de lectura.
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

        // El rango llega en fechas `Y-m-d`; el período persistido es mensual (`YYYY-MM`): se compara por mes.
        $monthFrom = self::month($query->periodFrom);
        $monthTo = self::month($query->periodTo);

        $settlements = $this->settlements->list(
            $query->carrierId,
            $query->status,
            $query->limit,
            $query->offset,
            $monthFrom,
            $monthTo,
        );

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

        return new Page($views, $this->settlements->countBy($query->carrierId, $query->status, $monthFrom, $monthTo));
    }

    /**
     * Mes (`YYYY-MM`) de una fecha `Y-m-d`; null si no hay fecha.
     */
    private static function month(?string $date): ?string
    {
        if ($date === null || strlen($date) < 7) {
            return null;
        }

        return substr($date, 0, 7);
    }
}
