<?php

declare(strict_types=1);

namespace Commerce\Pricing\Application\ReadModel;

use Commerce\Pricing\Domain\Model\PriceList;

/**
 * Proyección de lectura de una lista de precios para listados y API. Primitivos: no expone objetos de
 * dominio.
 */
final readonly class PriceListView
{
    private function __construct(
        public string $id,
        public string $name,
        public string $currency,
        public string $channel,
        public string $status,
    ) {}

    public static function fromDomain(PriceList $list): self
    {
        return new self(
            $list->id()->toString(),
            $list->name(),
            $list->currency(),
            $list->channel()->value,
            $list->status()->value,
        );
    }

    /**
     * @return array{id: string, name: string, currency: string, channel: string, status: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'currency' => $this->currency,
            'channel' => $this->channel,
            'status' => $this->status,
        ];
    }
}
