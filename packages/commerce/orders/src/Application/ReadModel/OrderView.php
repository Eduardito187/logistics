<?php

declare(strict_types=1);

namespace Commerce\Orders\Application\ReadModel;

use Commerce\Orders\Domain\Model\Order;
use Commerce\Orders\Domain\ValueObject\OrderLine;

/**
 * Proyección de lectura de una orden para listados y API. Primitivos: no expone objetos de dominio.
 */
final readonly class OrderView
{
    /**
     * @param array{customer_name: string, address_text: string, reference: ?string, phone: ?string, email: ?string} $shipTo
     * @param list<array{sku: string, product_name: string, unit_price_cents: int, quantity: int}>                   $lines
     */
    private function __construct(
        public string $id,
        public ?string $customerId,
        public string $status,
        public string $currency,
        public int $totalCents,
        public bool $shipmentRequested,
        public array $shipTo,
        public array $lines,
    ) {}

    public static function fromDomain(Order $order): self
    {
        return new self(
            $order->id()->toString(),
            $order->customerId()?->value,
            $order->status()->value,
            $order->currency(),
            $order->totalCents(),
            $order->shipmentRequested(),
            $order->shipTo()->toArray(),
            array_values(array_map(
                static fn (OrderLine $line): array => $line->toArray(),
                $order->lines(),
            )),
        );
    }

    /**
     * @return array{id: string, customer_id: ?string, status: string, currency: string, total_cents: int, shipment_requested: bool, ship_to: array{customer_name: string, address_text: string, reference: ?string, phone: ?string, email: ?string}, lines: list<array{sku: string, product_name: string, unit_price_cents: int, quantity: int}>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'customer_id' => $this->customerId,
            'status' => $this->status,
            'currency' => $this->currency,
            'total_cents' => $this->totalCents,
            'shipment_requested' => $this->shipmentRequested,
            'ship_to' => $this->shipTo,
            'lines' => $this->lines,
        ];
    }
}
