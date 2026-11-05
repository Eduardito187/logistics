<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\ReadModel;

/**
 * Respuesta de las líneas de despacho de una orden: la referencia legible del pedido, un resumen de grupos
 * por envío/destino (para la UI multishipping) y las líneas. Forma del envelope:
 *   { order_reference, destinations: [{shipment_reference, destination, line_count}], data: [DispatchLine] }
 */
final readonly class DispatchLinesView
{
    /**
     * @param list<array{shipment_reference: string|null, destination: array{type: string|null, name: string|null, address: string|null}, line_count: int}> $destinations
     * @param list<DispatchLineView>                                                                                                                        $lines
     */
    public function __construct(
        public string $orderReference,
        public array $destinations,
        public array $lines,
    ) {}

    /**
     * @return array{order_reference: string, destinations: list<array{shipment_reference: string|null, destination: array{type: string|null, name: string|null, address: string|null}, line_count: int}>, data: list<array<string, mixed>>}
     */
    public function toArray(): array
    {
        return [
            'order_reference' => $this->orderReference,
            'destinations' => $this->destinations,
            'data' => array_map(static fn (DispatchLineView $l): array => $l->toArray(), $this->lines),
        ];
    }
}
