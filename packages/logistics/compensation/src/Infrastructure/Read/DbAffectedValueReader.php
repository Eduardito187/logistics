<?php

declare(strict_types=1);

namespace Logistics\Compensation\Infrastructure\Read;

use Illuminate\Database\ConnectionInterface;
use Logistics\Compensation\Application\Contract\AffectedValueReader;
use Logistics\Compensation\Domain\Exception\CompensationException;
use Logistics\Compensation\Domain\ValueObject\AffectedLine;
use Logistics\Compensation\Domain\ValueObject\AffectedValue;
use Logistics\Compensation\Domain\ValueObject\CompensationScope;
use Logistics\Compensation\Domain\ValueObject\Money;

/**
 * Lee el valor afectado desde las proyecciones de shipments SIN acoplar al contexto (solo SQL de lectura).
 *
 * `B` es la suma de `unit_price × quantity` de las líneas según el alcance; `B_order` suma todas las líneas
 * de los envíos hermanos (mismo `order_reference`). Si alguna línea relevante no tiene `unit_price` o no es
 * BOB, devuelve null: regla 6 (solo emisión manual con base declarada).
 */
final readonly class DbAffectedValueReader implements AffectedValueReader
{
    public function __construct(
        private ConnectionInterface $db,
    ) {}

    public function affectedForShipment(
        string $shipmentId,
        CompensationScope $scope,
        array $affectedSkus = [],
    ): ?AffectedValue {
        $orderReference = $this->orderReferenceOf($shipmentId);

        // IDs de envíos que componen la base según el alcance.
        $baseShipmentIds = $scope === CompensationScope::Order && $orderReference !== null
            ? $this->siblingShipmentIds($orderReference)
            : [$shipmentId];

        $baseLines = $this->linesFor($baseShipmentIds, $scope === CompensationScope::Line ? $affectedSkus : []);
        if ($baseLines === null) {
            return null;
        }

        try {
            $affected = AffectedValue::fromLines($baseLines, null);
        } catch (CompensationException) {
            return null;
        }

        // Base de la orden (para el tope acumulado): todas las líneas del order_reference, sin filtrar SKUs.
        if ($orderReference === null) {
            return $affected;
        }

        $orderLines = $this->linesFor($this->siblingShipmentIds($orderReference), []);
        if ($orderLines === null) {
            return $affected;
        }

        $orderBase = Money::zero();
        foreach ($orderLines as $line) {
            $orderBase = $orderBase->add($line->lineValue());
        }

        return AffectedValue::fromLines($baseLines, $orderBase->cents >= $affected->base->cents ? $orderBase : $affected->base);
    }

    private function orderReferenceOf(string $shipmentId): ?string
    {
        $value = $this->db->table('shipments')->where('id', $shipmentId)->value('order_reference');

        return is_string($value) ? $value : null;
    }

    /**
     * @return list<string>
     */
    private function siblingShipmentIds(string $orderReference): array
    {
        $ids = $this->db->table('shipments')->where('order_reference', $orderReference)->pluck('id')->all();

        return array_values(array_filter($ids, 'is_string'));
    }

    /**
     * Devuelve las líneas como AffectedLine, o null si alguna no tiene precio o no es BOB.
     *
     * @param list<string> $shipmentIds
     * @param list<string> $skus
     * @return list<AffectedLine>|null
     */
    private function linesFor(array $shipmentIds, array $skus): ?array
    {
        if ($shipmentIds === []) {
            return null;
        }

        $query = $this->db->table('shipment_lines')->whereIn('shipment_id', $shipmentIds);
        if ($skus !== []) {
            $query->whereIn('sku', $skus);
        }
        $rows = $query->get(['sku', 'product_name', 'quantity', 'unit_price', 'unit_price_currency']);
        if ($rows->isEmpty()) {
            return null;
        }

        $lines = [];
        foreach ($rows as $row) {
            $currency = is_string($row->unit_price_currency) ? strtoupper($row->unit_price_currency) : null;
            if ($row->unit_price === null || $currency !== Money::DEFAULT_CURRENCY) {
                return null;
            }
            $cents = (int) round(((float) $row->unit_price) * 100);
            $lines[] = AffectedLine::of(
                is_string($row->product_name) ? $row->product_name : (string) $row->sku,
                (string) $row->sku,
                max(1, (int) $row->quantity),
                Money::of($cents, $currency),
            );
        }

        return $lines;
    }
}
