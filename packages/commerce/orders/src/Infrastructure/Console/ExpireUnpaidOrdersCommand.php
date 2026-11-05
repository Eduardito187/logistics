<?php

declare(strict_types=1);

namespace Commerce\Orders\Infrastructure\Console;

use Commerce\Orders\Application\Command\CancelOrder\CancelOrder;
use Commerce\Orders\Application\Contract\PaymentScheduleReader;
use Commerce\Orders\Domain\ValueObject\OrderStatus;
use Commerce\Orders\Infrastructure\Persistence\Eloquent\Model\OrderRecord;
use Illuminate\Console\Command;
use Illuminate\Support\CarbonImmutable;
use Logistics\Shared\Application\Command\CommandBus;

/**
 * Expira órdenes impagas: cancela las órdenes Pending cuya ventana de pago ya venció (ADR 020).
 *
 * Por cada orden Pending calcula la ventana según el método del pago iniciado (puerto read-only a
 * `commerce_payments`), o el `default` si no hay pago o el método no está mapeado. La cuenta regresiva
 * arranca en la fecha del pago (si existe) o en la de la orden. Si venció, despacha
 * CancelOrder(source='cron', reason='unpaid_timeout'). Idempotente: la cancelación lo es y solo se
 * recorren órdenes Pending, así que repetir la corrida no tiene efectos duplicados.
 *
 * Texto visible en inglés (regla de consola); comentarios en español.
 */
final class ExpireUnpaidOrdersCommand extends Command
{
    protected $signature = 'commerce:orders:expire-unpaid';

    protected $description = 'Cancel unpaid pending orders whose payment window has elapsed (per payment method).';

    public function handle(CommandBus $commands, PaymentScheduleReader $payments): int
    {
        $windows = $this->windows();
        $default = $windows['default'] ?? 720;
        $now = CarbonImmutable::now();
        $expired = 0;

        OrderRecord::query()
            ->where('status', OrderStatus::Pending->value)
            ->orderBy('created_at')
            ->each(function (OrderRecord $order) use ($commands, $payments, $windows, $default, $now, &$expired): void {
                $orderId = (string) $order->getKey();
                $payment = $payments->latestPendingForOrder($orderId);

                $minutes = $payment !== null
                    ? ($windows[$payment['method']] ?? $default)
                    : $default;

                $since = $payment !== null
                    ? CarbonImmutable::parse($payment['created_at'])
                    : CarbonImmutable::parse($order->created_at);

                // Aún dentro de la ventana: no expira.
                if ($now->lessThan($since->addMinutes($minutes))) {
                    return;
                }

                $commands->dispatch(new CancelOrder(
                    orderId: $orderId,
                    reason: 'unpaid_timeout',
                    source: 'cron',
                ));

                $expired++;
            });

        $this->info("Unpaid orders expired: {$expired}.");

        return self::SUCCESS;
    }

    /**
     * Ventanas de expiración por método (minutos), leídas de config/commerce.php.
     *
     * @return array<string, int>
     */
    private function windows(): array
    {
        /** @var mixed $raw */
        $raw = config('commerce.cancellation.unpaid_expiry_minutes', []);

        if (!is_array($raw)) {
            return [];
        }

        $windows = [];
        foreach ($raw as $method => $minutes) {
            if (is_string($method) && is_numeric($minutes)) {
                $windows[$method] = (int) $minutes;
            }
        }

        return $windows;
    }
}
