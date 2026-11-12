<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence\Backfill;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Tracking\Application\Contract\CustomerEmailBackfill;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;
use Logistics\Tracking\Infrastructure\ReadSide\DbCustomerContactReader;
use stdClass;

/**
 * Backfill SQL de `tracking_shipments.customer_email` desde `shipments` (solo lectura del otro contexto).
 *
 * Paginación por clave (keyset sobre `t.id`): cada lote lee a lo sumo `chunk` filas DELIVERED con email
 * vacío cuyo envío tiene email, y escribe con UPDATE guardado por `customer_email IS NULL`, así que
 * re-ejecutarlo (o correrlo junto a la proyección) no pisa nada. El email se normaliza con la misma regla
 * que la proyección; los inválidos se cuentan y quedan en null. Los emails no salen en ninguna salida (PII).
 */
final readonly class SqlCustomerEmailBackfill implements CustomerEmailBackfill
{
    private const int MAX_CHUNK = 5000;

    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function run(bool $dryRun, int $chunk): array
    {
        $db = $this->connections->connection();
        $size = max(1, min($chunk, self::MAX_CHUNK));
        $result = ['candidates' => 0, 'filled' => 0, 'invalid' => 0];
        $lastId = null;

        do {
            $rows = $this->candidates($db, $lastId, $size);

            foreach ($rows as $row) {
                $lastId = (string) $row->id;
                $result['candidates']++;

                $email = DbCustomerContactReader::normalizeEmail($row->shipment_email ?? null);
                if ($email === null) {
                    $result['invalid']++;

                    continue;
                }

                if ($dryRun) {
                    $result['filled']++;

                    continue;
                }

                $result['filled'] += $db->table('tracking_shipments')
                    ->where('id', $lastId)
                    ->whereNull('customer_email')
                    ->update(['customer_email' => $email]);
            }
        } while (count($rows) === $size);

        return $result;
    }

    /**
     * Siguiente lote de candidatos con id mayor a `afterId` (en orden de id).
     *
     * @return list<stdClass>
     */
    private function candidates(ConnectionInterface $db, ?string $afterId, int $size): array
    {
        $query = $db->table('tracking_shipments as t')
            ->join('shipments as s', 's.id', '=', 't.shipment_id')
            ->where('t.status', VisibleStatus::Delivered->value)
            ->whereNull('t.customer_email')
            ->whereNotNull('s.customer_email')
            ->orderBy('t.id')
            ->limit($size);

        if ($afterId !== null) {
            $query->where('t.id', '>', $afterId);
        }

        /** @var list<stdClass> $rows */
        $rows = array_values($query->get(['t.id', 's.customer_email as shipment_email'])->all());

        return $rows;
    }
}
