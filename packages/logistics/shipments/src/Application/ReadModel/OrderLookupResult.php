<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * Resultado de la consulta de pedidos: los envíos encontrados y cómo se llegó a ellos.
 *
 *  - `matchedBy`: `code` (código de pedido exacto), `label` (identificador de una etiqueta) o `search`
 *    (coincidencia parcial de texto);
 *  - `matchedShipmentId`: cuando se buscó por etiqueta, el envío al que pertenece esa etiqueta (un pedido
 *    con varios envíos devuelve sus hermanos; este es el del producto escaneado). Null en los demás casos.
 */
final readonly class OrderLookupResult
{
    public const MATCHED_BY_CODE = 'code';

    public const MATCHED_BY_LABEL = 'label';

    public const MATCHED_BY_SEARCH = 'search';

    /**
     * @param list<OrderLookupView> $items
     */
    public function __construct(
        public array $items,
        public string $matchedBy,
        public ?string $matchedShipmentId = null,
    ) {}

    public function isEmpty(): bool
    {
        return $this->items === [];
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{count: int, matched_by: string, matched_shipment_id: string|null}}
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(static fn (OrderLookupView $item): array => $item->toArray(), $this->items),
            'meta' => [
                'count' => count($this->items),
                'matched_by' => $this->matchedBy,
                'matched_shipment_id' => $this->matchedShipmentId,
            ],
        ];
    }
}
