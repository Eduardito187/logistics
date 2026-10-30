<?php

declare(strict_types=1);

namespace Commerce\Invoicing\Application\Command\IssueInvoiceForOrder;

use Logistics\Shared\Application\Command\Command;

/**
 * Intención de emitir la factura de una orden pagada.
 *
 * `orderId` es el UUID de la orden (invoicing no conoce el contexto orders: lo referencia por id). Las
 * líneas vienen aplanadas por el que despacha (p. ej. el puente orders.order_paid): cada una lleva la
 * descripción, la cantidad y el precio unitario bruto en centavos.
 */
final readonly class IssueInvoiceForOrder implements Command
{
    /**
     * @param list<array{description: string, quantity: int, unit_price_cents: int}> $lines
     */
    public function __construct(
        public string $orderId,
        public string $customerName,
        public ?string $customerTaxId,
        public string $currency,
        public array $lines,
    ) {}
}
