<?php

declare(strict_types=1);

namespace Commerce\Orders\Infrastructure\ReadModel;

use Commerce\Orders\Application\Contract\CartSnapshotReader;
use Illuminate\Database\ConnectionInterface;

/**
 * Lee la instantánea del carrito directamente de la tabla `commerce_carts` con el query builder (sin
 * los modelos Eloquent de `cart`): así `orders` no se acopla al dominio del otro contexto y cada uno
 * queda extraíble a microservicio. Read-only y tolerante a datos ausentes: si el carrito no existe,
 * devuelve null (el handler traduce eso a 422).
 *
 * Las líneas se guardan como JSON en la fila; el query builder no aplica los casts de Eloquent, así que
 * se decodifican acá. Se mapea `qty` (forma de `cart`) a `quantity` (forma de `orders`). El carrito no
 * guarda datos de entrega, por eso `ship_to` sale null (los aporta el cuerpo del checkout).
 */
final readonly class DbCartSnapshotReader implements CartSnapshotReader
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function snapshot(string $cartId): ?array
    {
        $cartId = trim($cartId);
        if ($cartId === '') {
            return null;
        }

        /** @var \stdClass|null $row */
        $row = $this->connection->table('commerce_carts')
            ->where('id', $cartId)
            ->first(['customer_id', 'currency', 'lines']);

        if ($row === null) {
            return null;
        }

        return [
            'lines' => $this->mapLines($row->lines),
            'currency' => is_string($row->currency) && $row->currency !== '' ? $row->currency : 'BOB',
            'customer_id' => $row->customer_id !== null ? (string) $row->customer_id : null,
            'ship_to' => null,
        ];
    }

    /**
     * Decodifica la columna JSON de líneas y la mapea a la forma que consume `orders`. Descarta entradas
     * malformadas de forma defensiva (lectura cruzada: nunca confía ciegamente en el otro contexto).
     *
     * @param  mixed                                                                                $rawLines valor crudo de la columna (string JSON o ya array según el driver)
     * @return list<array{sku: string, product_name: string, unit_price_cents: int, quantity: int}>
     */
    private function mapLines(mixed $rawLines): array
    {
        if (is_string($rawLines)) {
            $decoded = json_decode($rawLines, true);
        } else {
            $decoded = $rawLines;
        }

        if (!is_array($decoded)) {
            return [];
        }

        $lines = [];
        foreach ($decoded as $line) {
            if (!is_array($line)) {
                continue;
            }
            $sku = isset($line['sku']) ? (string) $line['sku'] : '';
            $name = isset($line['product_name']) ? (string) $line['product_name'] : '';
            if ($sku === '' || $name === '') {
                continue;
            }

            $lines[] = [
                'sku' => $sku,
                'product_name' => $name,
                'unit_price_cents' => isset($line['unit_price_cents']) ? (int) $line['unit_price_cents'] : 0,
                'quantity' => isset($line['qty']) ? (int) $line['qty'] : 0,
            ];
        }

        return $lines;
    }
}
