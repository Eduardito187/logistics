<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Backfill;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Shipments\Domain\ValueObject\DeliveryMode;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Tracking\Application\Contract\TrackingDatesReconciler;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Reconciliación SQL (Postgres) de `at_store_at` y `delivered_at`; ver el contrato para las reglas.
 *
 * Las tablas de otros contextos (`execution_deliveries`, `shipments`, `network_nodes`) se leen SOLO en
 * lectura y con guardas de existencia, para que corra igual en un docker nuevo sin esos datos. Fuera de
 * Postgres no hace nada.
 */
final readonly class SqlTrackingDatesReconciler implements TrackingDatesReconciler
{
    /** Margen, en segundos, para reconocer el corrimiento exacto del desplazamiento horario. */
    private const int SHIFT_TOLERANCE_SECONDS = 2;

    public function __construct(
        private ConnectionResolverInterface $connections,
        private string $appTimezone,
    ) {}

    public function run(bool $apply): array
    {
        $db = $this->connections->connection();
        assert($db instanceof Connection);
        $schema = $db->getSchemaBuilder();

        $result = ['at_store' => 0, 'delivered_shifted' => 0];

        if ($db->getDriverName() !== 'pgsql'
            || !$schema->hasTable('tracking_shipments')
            || !$schema->hasTable('execution_deliveries')) {
            return $result;
        }

        if ($schema->hasColumn('tracking_shipments', 'at_store_at')
            && $schema->hasColumn('execution_deliveries', 'store_dropped_at')
            && $schema->hasTable('shipments')
            && $schema->hasTable('network_nodes')) {
            $result['at_store'] = $this->atStore($db, $apply);
        }

        if ($schema->hasColumn('tracking_shipments', 'delivered_at')) {
            $result['delivered_shifted'] = $this->deliveredShifted($db, $apply);
        }

        return $result;
    }

    /**
     * Pedido de cliente (fuente no interna) con retiro en tienda, dejado por el chofer en SU tienda destino.
     */
    private function atStore(Connection $db, bool $apply): int
    {
        $nodeTypes = DeliveryMode::pickupNodeTypes();
        $internal = OrderSource::internalTransferValues();
        $closed = [VisibleStatus::Cancelled->value, VisibleStatus::Returned->value];

        $where = sprintf(
            <<<'SQL'
                d.shipment_id = t.shipment_id
                  AND s.id = d.shipment_id
                  AND d.store_dropped_at IS NOT NULL
                  AND d.store_drop_node_id = s.destination_node_id
                  AND s.destination_node_id IN (SELECT n.id FROM network_nodes AS n WHERE n.type IN (%s))
                  AND s.source NOT IN (%s)
                  AND t.at_store_at IS NULL
                  AND t.status NOT IN (%s)
            SQL,
            $this->placeholders($nodeTypes),
            $this->placeholders($internal),
            $this->placeholders($closed),
        );
        $bindings = [...$nodeTypes, ...$internal, ...$closed];

        if (!$apply) {
            return $this->count($db, "SELECT count(*) AS total FROM tracking_shipments AS t, execution_deliveries AS d, shipments AS s WHERE {$where}", $bindings);
        }

        return $db->affectingStatement(
            "UPDATE tracking_shipments AS t SET at_store_at = d.store_dropped_at FROM execution_deliveries AS d, shipments AS s WHERE {$where}",
            $bindings,
        );
    }

    /**
     * Entregas cuya fecha quedó corrida exactamente el desplazamiento de la zona de la app respecto del
     * momento real de la prueba de entrega.
     */
    private function deliveredShifted(Connection $db, bool $apply): int
    {
        // Desplazamiento de la zona de la app respecto de UTC, en positivo hacia el oeste (La Paz = 14400).
        $shift = -1 * (new DateTimeZone($this->appTimezone))->getOffset(new DateTimeImmutable('now'));
        if ($shift === 0) {
            return 0;
        }

        $where = <<<'SQL'
            d.shipment_id = t.shipment_id
              AND d.status = 'DELIVERED'
              AND d.pod_captured_at IS NOT NULL
              AND t.delivered_at IS NOT NULL
              AND abs(extract(epoch FROM (t.delivered_at - d.pod_captured_at)) - ?) <= ?
        SQL;
        $bindings = [$shift, self::SHIFT_TOLERANCE_SECONDS];

        if (!$apply) {
            return $this->count($db, "SELECT count(*) AS total FROM tracking_shipments AS t, execution_deliveries AS d WHERE {$where}", $bindings);
        }

        return $db->affectingStatement(
            "UPDATE tracking_shipments AS t SET delivered_at = d.pod_captured_at FROM execution_deliveries AS d WHERE {$where}",
            $bindings,
        );
    }

    /**
     * @param list<mixed> $bindings
     */
    private function count(Connection $db, string $sql, array $bindings): int
    {
        $row = $db->selectOne($sql, $bindings);

        return is_object($row) && is_numeric($row->total ?? null) ? (int) $row->total : 0;
    }

    /**
     * @param list<string> $values
     */
    private function placeholders(array $values): string
    {
        return implode(', ', array_fill(0, max(1, count($values)), '?'));
    }
}
