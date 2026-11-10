<?php

declare(strict_types=1);

namespace Commerce\Orders\Infrastructure\ReadModel;

use Commerce\Orders\Application\Contract\PaymentScheduleReader;
use Illuminate\Database\ConnectionInterface;

/**
 * Lee el pago iniciado de una orden directamente de la tabla `commerce_payments` con el query builder.
 *
 * No usa el modelo Eloquent ni el dominio de `payments`: así Orders no se acopla al otro contexto (mismo
 * criterio que DbStopEnrichmentReader en ejecución). Toma el pago pendiente más reciente de la orden; su
 * método fija la ventana de expiración y su `created_at` es el inicio de la cuenta regresiva.
 */
final readonly class DbPaymentScheduleReader implements PaymentScheduleReader
{
    public function __construct(
        private ConnectionInterface $connection,
    ) {}

    public function latestPendingForOrder(string $orderId): ?array
    {
        $row = $this->connection->table('commerce_payments')
            ->where('order_id', $orderId)
            ->where('status', 'pending')
            ->orderByDesc('created_at')
            ->first(['method', 'created_at']);

        if ($row === null) {
            return null;
        }

        return [
            'method' => (string) $row->method,
            'created_at' => (string) $row->created_at,
        ];
    }
}
