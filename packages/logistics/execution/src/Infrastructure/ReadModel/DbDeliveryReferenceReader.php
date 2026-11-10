<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\ReadModel;

use Illuminate\Database\ConnectionInterface;
use Logistics\Execution\Application\Contract\DeliveryReferenceReader;
use Throwable;

/**
 * Lee la referencia legible del envío (pedido/cliente/dirección) desde `shipments` por el query builder,
 * en solo lectura y en lote (whereIn), sin acoplar Ejecución al dominio de shipments. El "pedido" es el
 * `external_id` del envío (código legible del pedido de origen), no su UUID. Tolerante a datos/tabla
 * ausentes: devuelve nulls.
 */
final readonly class DbDeliveryReferenceReader implements DeliveryReferenceReader
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function forShipmentIds(array $shipmentIds): array
    {
        $ids = array_values(array_unique(array_filter(
            $shipmentIds,
            static fn (string $id): bool => trim($id) !== '',
        )));
        if ($ids === []) {
            return [];
        }

        try {
            $rows = $this->connection->table('shipments')
                ->whereIn('id', $ids)
                ->get(['id', 'external_id', 'customer_name', 'address_text']);
        } catch (Throwable) {
            return [];
        }

        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row->id] = [
                'order_reference' => self::str($row->external_id ?? null),
                'customer_name' => self::str($row->customer_name ?? null),
                'address' => self::str($row->address_text ?? null),
            ];
        }

        return $result;
    }

    /** Normaliza a string no vacío o null. */
    private static function str(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
