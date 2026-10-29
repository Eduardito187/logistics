<?php

declare(strict_types=1);

namespace Commerce\Pricing\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se fijó (alta o actualización) el precio al público —IVA incluido— de un SKU dentro de una lista.
 *
 * El precio se persiste fila a fila (puede haber millones): el agregado solo custodia la invariante y
 * emite este hecho para el outbox. El monto va en centavos (entero) + moneda.
 */
final readonly class PriceSet extends BaseDomainEvent
{
    public function __construct(
        string $aggregateId,
        public string $sku,
        public int $grossAmountCents,
        public string $currency,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'pricing.price_set';
    }

    public function payload(): array
    {
        return [
            'sku' => $this->sku,
            'gross_amount_cents' => $this->grossAmountCents,
            'currency' => $this->currency,
        ];
    }
}
