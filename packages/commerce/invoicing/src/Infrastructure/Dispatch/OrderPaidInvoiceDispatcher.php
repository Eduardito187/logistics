<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Infrastructure\Dispatch;

use Commerce\Invoicing\Application\Command\IssueInvoiceForOrder\IssueInvoiceForOrder;
use Illuminate\Support\Facades\DB;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Throwable;

/**
 * Puente orders → invoicing (ADR 018): cuando una orden se paga (`orders.order_paid`), emite su factura.
 *
 * Adaptador de INTEGRACIÓN (capa Infrastructure, anti-corruption): consume el evento por su NOMBRE y lee
 * los datos de la orden de la tabla `commerce_orders` en modo read-only (patrón cross-context de la
 * skill). NUNCA hace `use` de una clase del contexto orders ni depende de él por composer: así invoicing
 * sigue siendo extraíble a microservicio. Despacha su propio `IssueInvoiceForOrder` por el CommandBus.
 *
 * Idempotencia (el outbox reentrega at-least-once): `IssueInvoiceForOrderHandler` deduplica por
 * `order_id` (una orden factura a lo sumo una vez), así que un reintento no crea una factura duplicada.
 */
final readonly class OrderPaidInvoiceDispatcher implements MessageDispatcher
{
    /** Tabla read-only de órdenes (dueño: `commerce/orders`). */
    private const ORDERS_TABLE = 'commerce_orders';

    public function __construct(
        private CommandBus $commandBus,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'orders.order_paid') {
            return;
        }

        $orderId = $message->aggregateId();

        try {
            // Lectura cruzada read-only: si la tabla aún no existe (orders no desplegado) o falla, degrada
            // silenciosamente en vez de romper el relay. El query builder devuelve stdClass (o null); se
            // estrecha a object|null para que rawValue() (que accede por propiedad) lo reciba tipado.
            /** @var object|null $order */
            $order = DB::table(self::ORDERS_TABLE)->find($orderId);
        } catch (Throwable) {
            return;
        }

        // Combina los guards de orden inexistente y de líneas vacías en un solo retorno (≤3 returns).
        $lines = $order === null ? [] : $this->mapLines($this->decode($this->rawValue($order, 'lines')));
        if ($order === null || $lines === []) {
            return;
        }

        $shipTo = $this->decode($this->rawValue($order, 'ship_to'));

        $this->commandBus->dispatch(new IssueInvoiceForOrder(
            orderId: $orderId,
            customerName: $this->str($shipTo, 'customer_name', 'S/N'),
            customerTaxId: null,
            currency: $this->str($order, 'currency', 'BOB'),
            lines: $lines,
        ));
    }

    /**
     * Mapea las líneas de la orden (sku, product_name, unit_price_cents, quantity) a la forma que espera
     * el comando de facturación (description, quantity, unit_price_cents).
     *
     * @param  array<array-key, mixed>                                                $rawLines
     * @return list<array{description: string, quantity: int, unit_price_cents: int}>
     */
    private function mapLines(array $rawLines): array
    {
        $lines = [];
        foreach ($rawLines as $rawLine) {
            if (!is_array($rawLine)) {
                continue;
            }
            $description = $this->str($rawLine, 'product_name', '');
            if ($description === '') {
                $description = $this->str($rawLine, 'sku', 'Producto');
            }
            $quantity = $this->int($rawLine, 'quantity');
            if ($quantity < 1) {
                continue;
            }
            $lines[] = [
                'description' => $description,
                'quantity' => $quantity,
                'unit_price_cents' => $this->int($rawLine, 'unit_price_cents'),
            ];
        }

        return $lines;
    }

    /**
     * Decodifica un valor JSONB (string del query builder, o ya array) a arreglo asociativo.
     *
     * @return array<array-key, mixed>
     */
    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (is_string($value)) {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function rawValue(object $row, string $field): mixed
    {
        return $row->{$field} ?? null;
    }

    /**
     * @param object|array<array-key, mixed> $source
     */
    private function str(object | array $source, string $field, string $default): string
    {
        $value = is_array($source) ? ($source[$field] ?? null) : ($source->{$field} ?? null);
        if (is_scalar($value)) {
            $value = trim((string) $value);

            return $value !== '' ? $value : $default;
        }

        return $default;
    }

    /**
     * @param array<array-key, mixed> $source
     */
    private function int(array $source, string $field): int
    {
        $value = $source[$field] ?? null;

        return is_numeric($value) ? (int) $value : 0;
    }
}
