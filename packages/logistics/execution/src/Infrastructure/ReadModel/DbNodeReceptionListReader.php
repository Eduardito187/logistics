<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use DateTimeImmutable;
use Exception;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Logistics\Execution\Application\Contract\NodeReceptionListReader;
use Logistics\Execution\Application\ReadModel\NodeReceptionSummaryView;

/**
 * Listado de recepciones en nodo con query builder, en TRES consultas fijas por página (nunca N+1):
 *
 * 1. COUNT(*) del filtro (nodo destino + estado), servido por el índice (destination_node_id, status, opened_at);
 * 2. la página (LIMIT/OFFSET) con LEFT JOIN a shipments para la referencia legible (pedido y cliente);
 * 3. los productos recibidos (distintos) de TODAS las recepciones de la página, en un solo whereIn.
 *
 * Los conteos de faltantes/sobrantes salen de la diferencia de conjuntos esperados vs. recibidos, igual que
 * en el agregado. Lectura read-only de shipments: sin acoplar el dominio de ejecución a otro contexto.
 */
final readonly class DbNodeReceptionListReader implements NodeReceptionListReader
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function page(?string $nodeId, ?string $status, int $limit, int $offset): array
    {
        $total = $this->filtered($nodeId, $status)->count();
        if ($total === 0) {
            return ['items' => [], 'total' => 0];
        }

        $rows = $this->filtered($nodeId, $status)
            ->leftJoin('shipments', 'shipments.id', '=', 'execution_node_receptions.shipment_id')
            ->orderByDesc('execution_node_receptions.opened_at')
            ->orderByDesc('execution_node_receptions.id')
            ->limit($limit)
            ->offset($offset)
            ->get([
                'execution_node_receptions.id',
                'execution_node_receptions.shipment_id',
                'execution_node_receptions.origin_node_id',
                'execution_node_receptions.destination_node_id',
                'execution_node_receptions.corridor',
                'execution_node_receptions.status',
                'execution_node_receptions.expected_unit_ids',
                'execution_node_receptions.opened_at',
                'execution_node_receptions.reconciled_at',
                'shipments.order_reference',
                'shipments.external_id',
                'shipments.customer_name',
            ]);

        $receivedByReception = $this->receivedUnitIds(
            array_values($rows->map(static fn (object $row): string => (string) ($row->id ?? ''))->values()->all()),
        );

        $items = [];
        foreach ($rows as $row) {
            $id = (string) $row->id;
            $items[] = NodeReceptionSummaryView::fromIds(
                $id,
                (string) $row->shipment_id,
                self::nullableString($row->order_reference ?? null) ?? self::nullableString($row->external_id ?? null),
                self::nullableString($row->customer_name ?? null),
                (string) $row->origin_node_id,
                (string) $row->destination_node_id,
                (string) $row->corridor,
                (string) $row->status,
                self::decodeIds($row->expected_unit_ids ?? null),
                $receivedByReception[$id] ?? [],
                self::atom($row->opened_at ?? null) ?? '',
                self::atom($row->reconciled_at ?? null),
            );
        }

        return ['items' => $items, 'total' => $total];
    }

    private function filtered(?string $nodeId, ?string $status): Builder
    {
        $query = $this->connection->table('execution_node_receptions');
        if ($nodeId !== null) {
            $query->where('execution_node_receptions.destination_node_id', $nodeId);
        }
        if ($status !== null) {
            $query->where('execution_node_receptions.status', $status);
        }

        return $query;
    }

    /**
     * Productos recibidos (distintos) de cada recepción de la página, en UNA consulta.
     *
     * @param  list<string>                $receptionIds
     * @return array<string, list<string>> reception id => unit ids
     */
    private function receivedUnitIds(array $receptionIds): array
    {
        $ids = array_values(array_filter($receptionIds, static fn (string $id): bool => $id !== ''));
        if ($ids === []) {
            return [];
        }

        $map = [];
        $rows = $this->connection->table('execution_node_reception_units')
            ->whereIn('node_reception_id', $ids)
            ->distinct()
            ->get(['node_reception_id', 'unit_id']);
        foreach ($rows as $row) {
            $map[(string) $row->node_reception_id][] = (string) $row->unit_id;
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private static function decodeIds(mixed $value): array
    {
        $decoded = is_string($value) ? json_decode($value, true) : $value;
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $id) {
            if (is_scalar($id) && trim((string) $id) !== '') {
                $ids[] = trim((string) $id);
            }
        }

        return $ids;
    }

    /**
     * Normaliza una marca de tiempo de la base a ISO 8601 (DATE_ATOM), como el resto de las vistas.
     */
    private static function atom(mixed $value): ?string
    {
        if (!is_string($value) || $value === '') {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->format(DATE_ATOM);
        } catch (Exception) {
            return $value;
        }
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? (string) $value : null;
    }
}
