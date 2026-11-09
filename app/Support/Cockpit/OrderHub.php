<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use Commerce\Invoicing\Application\Query\GetInvoiceByOrder\GetInvoiceByOrder;
use Commerce\Invoicing\Application\ReadModel\InvoiceView;
use Commerce\Orders\Application\Query\GetOrder\GetOrder;
use Commerce\Orders\Application\ReadModel\OrderView;
use Commerce\Payments\Application\Query\GetPaymentByOrder\GetPaymentByOrder;
use Commerce\Payments\Application\ReadModel\PaymentView;
use Illuminate\Support\Facades\DB;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Compositor de LECTURA del "hub 360" de un pedido comercial (OMS): agrega la ficha del pedido (cliente,
 * dirección de entrega, líneas, total), el estado de pago y la factura asociada. Todo por el QueryBus
 * (CQRS, contextos commerce). Tolerante: cada fuente en su try/catch. SOLO lectura; NUNCA expone el id
 * (UUID) como texto: la referencia es el nombre del cliente y los datos del pedido.
 *
 * Si una fuente no está disponible en el entorno (p. ej. aún no hay pago o factura), su sección degrada a
 * null sin romper la página.
 */
final class OrderHub
{
    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * Todo lo que la vista del hub del pedido necesita, en primitivos y tolerante.
     *
     * @return array{
     *   order: array<string, mixed>|null,
     *   payment: array{method: string, status: string, amount_cents: int|null, currency: string|null, gateway_ref: string|null}|null,
     *   invoice: array{number: string|null, status: string, total_cents: int, issued_at: string|null}|null,
     *   shipments: list<array{id: string, reference: string, status: string}>,
     * }
     */
    public function overview(string $orderId): array
    {
        return [
            'order' => $this->order($orderId),
            'payment' => $this->payment($orderId),
            'invoice' => $this->invoice($orderId),
            'shipments' => $this->shipments($orderId),
        ];
    }

    /**
     * Ficha del pedido (cliente, dirección, líneas, total), o null si no existe.
     *
     * @return array<string, mixed>|null
     */
    private function order(string $orderId): ?array
    {
        try {
            $view = $this->queryBus->ask(new GetOrder($orderId));
            if (!$view instanceof OrderView) {
                return null;
            }

            $ship = $view->shipTo;
            $str = static fn (string $key): ?string => isset($ship[$key]) && is_string($ship[$key]) && $ship[$key] !== ''
                ? $ship[$key]
                : null;

            $lines = [];
            $items = 0;
            foreach ($view->lines as $line) {
                if (!is_array($line)) {
                    continue;
                }
                $qty = isset($line['quantity']) ? (int) $line['quantity'] : 0;
                $items += $qty;
                $lines[] = [
                    'sku' => isset($line['sku']) ? (string) $line['sku'] : '',
                    'product_name' => isset($line['product_name']) ? (string) $line['product_name'] : 'Producto',
                    'quantity' => $qty,
                    'unit_price_cents' => isset($line['unit_price_cents']) ? (int) $line['unit_price_cents'] : 0,
                ];
            }

            return [
                'status' => $view->status,
                'currency' => $view->currency,
                'total_cents' => $view->totalCents,
                'shipment_requested' => $view->shipmentRequested,
                'items' => $items,
                'customer_name' => $str('customer_name') ?? 'Cliente',
                'address_text' => $str('address_text'),
                'address_reference' => $str('reference'),
                'phone' => $str('phone'),
                'email' => $str('email'),
                'lines' => $lines,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Estado de pago del pedido, o null si todavía no se inició un pago.
     *
     * @return array{method: string, status: string, amount_cents: int|null, currency: string|null, gateway_ref: string|null}|null
     */
    private function payment(string $orderId): ?array
    {
        try {
            $view = $this->queryBus->ask(new GetPaymentByOrder($orderId));
            if (!$view instanceof PaymentView) {
                return null;
            }

            $amount = $view->amount;
            $cents = null;
            foreach (['cents', 'amount_cents', 'total_cents'] as $key) {
                if (isset($amount[$key]) && is_numeric($amount[$key])) {
                    $cents = (int) $amount[$key];
                    break;
                }
            }
            $currency = isset($amount['currency']) && is_string($amount['currency']) ? $amount['currency'] : null;

            return [
                'method' => $view->method,
                'status' => $view->status,
                'amount_cents' => $cents,
                'currency' => $currency,
                'gateway_ref' => $view->gatewayRef,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Factura del pedido, o null si no se emitió (o el contexto de invoicing no está disponible).
     *
     * @return array{number: string|null, status: string, total_cents: int, issued_at: string|null}|null
     */
    private function invoice(string $orderId): ?array
    {
        try {
            $view = $this->queryBus->ask(new GetInvoiceByOrder($orderId));
            if (!$view instanceof InvoiceView) {
                return null;
            }

            return [
                'number' => $view->number,
                'status' => $view->status,
                'total_cents' => $view->totalCents,
                'issued_at' => $view->issuedAt,
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Envíos nacidos de este pedido (puente OMS→logística, ADR 018): `shipments.order_reference = orderId`.
     * En multishipping la orden se reparte en varios envíos (uno por destino). Devuelve id (para enlazar a
     * la ficha 360 del envío) + referencia legible (nº de envío) + estado. Lectura directa tolerante.
     *
     * @return list<array{id: string, reference: string, status: string}>
     */
    private function shipments(string $orderId): array
    {
        try {
            return array_values(DB::table('shipments')
                ->where('order_reference', $orderId)
                ->orderBy('external_id')
                ->limit(20)
                ->get(['id', 'external_id', 'status'])
                ->map(static fn ($r): array => [
                    'id' => (string) $r->id,
                    'reference' => (string) $r->external_id,
                    'status' => (string) $r->status,
                ])
                ->all());
        } catch (Throwable) {
            return [];
        }
    }
}
