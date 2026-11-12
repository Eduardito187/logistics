<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Backfill;

use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Tracking\Application\Contract\DeliveredAtBackfill;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Backfill SQL (Postgres) de `tracking_shipments.delivered_at`; ver el contrato para el orden de fuentes.
 *
 * Cada paso es un único UPDATE ... FROM acotado por `delivered_at IS NULL` y estado entregado/devuelto, así
 * que es IDEMPOTENTE y no pisa valores ya fijados por la proyección. Las tablas de otros contextos
 * (`execution_deliveries`, `outbox_messages`) se leen SOLO en lectura y con guardas de existencia, para que
 * el comando corra igual en un docker nuevo sin esos datos. Fuera de Postgres solo aplica el último recurso.
 */
final readonly class SqlDeliveredAtBackfill implements DeliveredAtBackfill
{
    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function run(bool $useUpdatedAtFallback = true): array
    {
        $db = $this->connections->connection();
        assert($db instanceof Connection);
        $schema = $db->getSchemaBuilder();

        $result = ['execution' => 0, 'outbox_delivered' => 0, 'status_history' => 0, 'updated_at' => 0];

        if (!$schema->hasTable('tracking_shipments') || !$schema->hasColumn('tracking_shipments', 'delivered_at')) {
            return $result;
        }

        $statuses = "'".VisibleStatus::Delivered->value."', '".VisibleStatus::Returned->value."'";
        $pending = "t.delivered_at IS NULL AND t.status IN ({$statuses})";

        if ($db->getDriverName() === 'pgsql') {
            if ($schema->hasTable('execution_deliveries')) {
                $result['execution'] = $this->update($db, <<<SQL
                    UPDATE tracking_shipments AS t
                    SET delivered_at = COALESCE(d.pod_captured_at, d.updated_at)
                    FROM execution_deliveries AS d
                    WHERE d.shipment_id = t.shipment_id
                      AND d.status = 'DELIVERED'
                      AND COALESCE(d.pod_captured_at, d.updated_at) IS NOT NULL
                      AND {$pending}
                SQL);
            }

            if ($schema->hasTable('outbox_messages')) {
                $result['outbox_delivered'] = $this->update($db, <<<SQL
                    UPDATE tracking_shipments AS t
                    SET delivered_at = src.first_at
                    FROM (
                        SELECT payload->>'shipment_id' AS shipment_id, MIN(created_at) AS first_at
                        FROM outbox_messages
                        WHERE event_name = 'execution.shipment_delivered'
                        GROUP BY payload->>'shipment_id'
                    ) AS src
                    WHERE src.shipment_id = t.shipment_id::text
                      AND {$pending}
                SQL);

                $result['status_history'] = $this->update($db, <<<SQL
                    UPDATE tracking_shipments AS t
                    SET delivered_at = src.first_at
                    FROM (
                        SELECT payload->>'tracking_id' AS tracking_id, MIN(created_at) AS first_at
                        FROM outbox_messages
                        WHERE event_name = 'tracking.status_changed'
                          AND payload->>'status' = 'DELIVERED'
                        GROUP BY payload->>'tracking_id'
                    ) AS src
                    WHERE src.tracking_id = t.id::text
                      AND {$pending}
                SQL);
            }
        }

        if ($useUpdatedAtFallback) {
            // Último recurso documentado: aproximación por la última modificación de la fila.
            $result['updated_at'] = $this->update($db, <<<SQL
                UPDATE tracking_shipments AS t
                SET delivered_at = t.updated_at
                WHERE t.updated_at IS NOT NULL
                  AND {$pending}
            SQL);
        }

        return $result;
    }

    private function update(Connection $db, string $sql): int
    {
        return $db->affectingStatement($sql);
    }
}
