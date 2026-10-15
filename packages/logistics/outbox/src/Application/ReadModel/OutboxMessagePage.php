<?php

declare(strict_types=1);

namespace Logistics\Outbox\Application\ReadModel;

/**
 * Página de mensajes del outbox: los items proyectados más los metadatos de paginación.
 *
 * Forma primitiva lista para la API (envelope data + meta).
 */
final readonly class OutboxMessagePage
{
    /**
     * @param list<OutboxMessageView> $items
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
    ) {}

    public function lastPage(): int
    {
        return $this->perPage > 0 ? max(1, (int) ceil($this->total / $this->perPage)) : 1;
    }

    /**
     * @return array{data: list<array<string, mixed>>, meta: array{total: int, page: int, per_page: int, last_page: int}}
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(static fn (OutboxMessageView $view): array => $view->toArray(), $this->items),
            'meta' => [
                'total' => $this->total,
                'page' => $this->page,
                'per_page' => $this->perPage,
                'last_page' => $this->lastPage(),
            ],
        ];
    }
}
