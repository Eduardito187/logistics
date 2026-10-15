<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Domain\ValueObject;

use Logistics\FleetOps\Domain\Exception\FleetOpsException;

/**
 * Renglón de una orden de mantenimiento: una descripción, la cantidad y los costos de repuesto y mano de
 * obra (ambos en Money, centavos). El total de la línea es (repuesto + mano de obra) × cantidad; el costo
 * total de la orden se obtiene sumando los `lineTotal()` de todos sus ítems.
 *
 * Se persiste dentro del jsonb `items` de la orden; la moneda la aporta la orden (columna `currency`),
 * por eso `toArray()` no la incluye y `fromArray()` la recibe aparte.
 */
final readonly class MaintenanceItem
{
    private function __construct(
        public string $description,
        public int $qty,
        public Money $partCost,
        public Money $laborCost,
    ) {}

    public static function of(string $description, int $qty, Money $partCost, Money $laborCost): self
    {
        $description = trim($description);
        if ($description === '') {
            throw FleetOpsException::because('El ítem de mantenimiento requiere una descripción.');
        }
        if ($qty <= 0) {
            throw FleetOpsException::because("La cantidad del ítem debe ser mayor que cero: {$qty}.");
        }
        if ($partCost->currency !== $laborCost->currency) {
            throw FleetOpsException::because('El repuesto y la mano de obra deben estar en la misma moneda.');
        }

        return new self($description, $qty, $partCost, $laborCost);
    }

    /**
     * Total de la línea: (repuesto + mano de obra) × cantidad.
     */
    public function lineTotal(): Money
    {
        return $this->partCost->add($this->laborCost)->multiply((float) $this->qty);
    }

    /**
     * Reconstruye el ítem desde su forma primitiva (jsonb) más la moneda de la orden.
     *
     * @param array{description: string, qty: int, part_cost_cents: int, labor_cost_cents: int} $data
     */
    public static function fromArray(array $data, string $currency): self
    {
        return self::of(
            $data['description'],
            $data['qty'],
            Money::of($data['part_cost_cents'], $currency),
            Money::of($data['labor_cost_cents'], $currency),
        );
    }

    /**
     * Forma primitiva para el jsonb `items` (sin moneda: la guarda la orden).
     *
     * @return array{description: string, qty: int, part_cost_cents: int, labor_cost_cents: int}
     */
    public function toArray(): array
    {
        return [
            'description' => $this->description,
            'qty' => $this->qty,
            'part_cost_cents' => $this->partCost->cents,
            'labor_cost_cents' => $this->laborCost->cents,
        ];
    }
}
