<?php

declare(strict_types=1);

namespace Logistics\SapConnector\Application\Dto;

/**
 * Pedido leído de SAP y traducido al modelo propio de la plataforma (primitivos). Es lo que el
 * conector entrega: NO conoce el package shipments. Quien consuma estos DTOs los convertirá en el
 * comando de recepción de aquel contexto (source = SAP). Así se respeta la frontera de ADR 002.
 */
final readonly class ImportedOrderDto
{
    /**
     * @param list<ImportedOrderLineDto> $lines
     */
    public function __construct(
        public string $externalId,
        public string $customerName,
        public string $addressText,
        public ?string $addressReference,
        public array $lines,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'external_id' => $this->externalId,
            'customer_name' => $this->customerName,
            'address_text' => $this->addressText,
            'address_reference' => $this->addressReference,
            'lines' => array_map(
                static fn (ImportedOrderLineDto $line): array => $line->toArray(),
                $this->lines,
            ),
        ];
    }
}
