<?php

declare(strict_types=1);

namespace Logistics\Planning\Application\ReadModel;

/**
 * Página de candidatos de un viaje más su contexto: desde dónde se midió la cercanía (`anchor`) y cuánto
 * del camión ya está ocupado por lo elegido (`capacity`).
 *
 * - `anchor`: {kind: node|shipment, name} — la base del camión o el último pedido elegido; null si no hay
 *   un punto de referencia con ubicación.
 * - `capacity`: lo usado por lo ya elegido y el máximo del camión. `max_units` es null: la flota no define
 *   un tope de unidades (solo peso y volumen).
 */
final readonly class TripCandidatesView
{
    /**
     * @param list<TripCandidateView>                                                                                             $items
     * @param array{kind: string, name: string}|null                                                                              $anchor
     * @param array{used_units: int, max_units: int|null, used_kg: float, max_kg: float|null, used_m3: float, max_m3: float|null} $capacity
     */
    public function __construct(
        public array $items,
        public int $total,
        public ?array $anchor,
        public array $capacity,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function itemsToArray(): array
    {
        return array_map(static fn (TripCandidateView $item): array => $item->toArray(), $this->items);
    }
}
