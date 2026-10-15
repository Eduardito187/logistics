<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

use Logistics\FleetOps\Domain\Exception\FleetOpsException;

/**
 * Carga transportada en una salida: descripción libre, peso en kilos y un detalle opcional de ítems.
 *
 * Es un value object inmutable: no identifica mercancía del catálogo (eso vive en otro contexto), solo
 * describe lo que salió en el vehículo para la operación de flota. Todo es opcional: hay salidas sin
 * carga declarada (traslado, taller).
 */
final readonly class Load
{
    /**
     * @param list<array{name: string, qty: int}> $items
     */
    private function __construct(
        public ?string $description,
        public ?float $weightKg,
        public array $items,
    ) {}

    /**
     * @param list<array{name: string, qty: int}> $items
     */
    public static function of(?string $description, ?float $weightKg = null, array $items = []): self
    {
        $description = $description !== null ? trim($description) : null;
        if ($description === '') {
            $description = null;
        }
        if ($weightKg !== null && $weightKg < 0) {
            throw FleetOpsException::because("El peso de la carga no puede ser negativo: {$weightKg}.");
        }

        return new self($description, $weightKg, array_values($items));
    }

    /**
     * Carga vacía (salida sin carga declarada).
     */
    public static function empty(): self
    {
        return new self(null, null, []);
    }

    /**
     * Reconstruye la carga desde su forma primitiva (entrada de comando o fila persistida).
     *
     * @param array{description?: string|null, weight_kg?: float|int|null, items?: list<array{name: string, qty: int}>} $data
     */
    public static function fromArray(array $data): self
    {
        $weight = $data['weight_kg'] ?? null;

        return self::of(
            $data['description'] ?? null,
            $weight !== null ? (float) $weight : null,
            $data['items'] ?? [],
        );
    }

    /**
     * @return array{description: string|null, weight_kg: float|null, items: list<array{name: string, qty: int}>}
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'weight_kg' => $this->weightKg,
            'items' => $this->items,
        ];
    }
}
