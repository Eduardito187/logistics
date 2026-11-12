<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use DateTimeImmutable;
use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\ScanBatchLedger;

/**
 * Libro de ítems de lote sobre `execution_scan_batch_items` (PK = clave de idempotencia), con respaldo en
 * `execution_scan_events.idempotency_key` (único) para reconocer escaneos ya enviados por su endpoint
 * individual. Query builder, sin modelos: es un detalle de infraestructura del lote.
 */
final readonly class DbScanBatchLedger implements ScanBatchLedger
{
    private const string TABLE = 'execution_scan_batch_items';

    public const string ACCEPTED = 'accepted';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function wasAccepted(string $idempotencyKey): bool
    {
        $key = trim($idempotencyKey);
        if ($key === '') {
            return false;
        }

        $inLedger = $this->connection->table(self::TABLE)
            ->where('idempotency_key', $key)
            ->where('result', self::ACCEPTED)
            ->exists();

        return $inLedger || $this->connection->table('execution_scan_events')->where('idempotency_key', $key)->exists();
    }

    public function remember(
        string $idempotencyKey,
        string $kind,
        ?string $shipmentId,
        string $result,
        ?string $problemCode,
        ?string $userId,
    ): void {
        $now = (new DateTimeImmutable)->format(DATE_ATOM);

        $this->connection->table(self::TABLE)->upsert(
            [[
                'idempotency_key' => trim($idempotencyKey),
                'kind' => $kind,
                'shipment_id' => $shipmentId,
                'result' => $result,
                'problem_code' => $problemCode,
                'user_id' => $userId,
                'processed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]],
            ['idempotency_key'],
            ['kind', 'shipment_id', 'result', 'problem_code', 'user_id', 'processed_at', 'updated_at'],
        );
    }
}
