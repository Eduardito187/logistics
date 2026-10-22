<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\ShipmentUnitProductReader;

/**
 * Lee el producto de cada bulto con el query builder (sin el Eloquent de shipments → sin acople al otro
 * contexto): una sola consulta en lote shipment_units LEFT JOIN shipment_lines.
 *
 * `shipment_units.id` es uuid en PostgreSQL: los ids que no tienen forma de uuid (ids de etiqueta, códigos
 * manuales) se descartan antes de consultar para no provocar un error de casteo.
 */
final readonly class DbShipmentUnitProductReader implements ShipmentUnitProductReader
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function forUnitIds(array $unitIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $unitIds,
            static fn (string $id): bool => preg_match(self::UUID_PATTERN, $id) === 1,
        )));
        if ($ids === []) {
            return [];
        }

        $rows = $this->connection->table('shipment_units')
            ->leftJoin('shipment_lines', 'shipment_lines.id', '=', 'shipment_units.line_id')
            ->whereIn('shipment_units.id', $ids)
            ->get([
                'shipment_units.id as unit_id',
                'shipment_units.unit_index',
                'shipment_units.unit_total',
                'shipment_units.piece_index',
                'shipment_units.piece_total',
                'shipment_lines.sku',
                'shipment_lines.product_name',
                'shipment_lines.product_image_url',
            ]);

        $products = [];
        foreach ($rows as $row) {
            $products[(string) $row->unit_id] = [
                'sku' => self::nullableString($row->sku ?? null),
                'product_name' => self::nullableString($row->product_name ?? null),
                'product_image_url' => self::nullableString($row->product_image_url ?? null),
                'unit_index' => self::nullableInt($row->unit_index ?? null),
                'unit_total' => self::nullableInt($row->unit_total ?? null),
                'piece_index' => self::nullableInt($row->piece_index ?? null),
                'piece_total' => self::nullableInt($row->piece_total ?? null),
            ];
        }

        return $products;
    }

    private static function nullableString(mixed $value): ?string
    {
        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
