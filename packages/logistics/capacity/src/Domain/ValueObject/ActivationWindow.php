<?php

declare(strict_types=1);

namespace Logistics\Capacity\Domain\ValueObject;

use Logistics\Capacity\Domain\Exception\CapacityException;

/**
 * Una ventana a activar en la activación por rango: la franja horaria, la unidad de capacidad y la
 * cantidad (cupo) que tendrá cada bucket generado para esa franja/unidad. Es la pieza con la que el
 * operador dice "de 08:00 a 12:00, 40 entregas".
 *
 * Su forma primitiva (toArray/fromArray) es lo que viaja en el comando y en el job encolado: nada de
 * objetos de dominio cruza la cola (regla 7 y serialización del job).
 */
final readonly class ActivationWindow
{
    private function __construct(
        public TimeSlot $timeSlot,
        public CapacityUnit $unit,
        public Quantity $quantity,
    ) {}

    public static function of(TimeSlot $timeSlot, CapacityUnit $unit, Quantity $quantity): self
    {
        return new self($timeSlot, $unit, $quantity);
    }

    /**
     * Construye desde su forma primitiva (la que llega del comando/job).
     *
     * @param array<string, mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $timeSlot = isset($raw['time_slot']) && is_string($raw['time_slot']) ? $raw['time_slot'] : '';
        $unitRaw = isset($raw['unit']) && is_string($raw['unit']) ? $raw['unit'] : '';
        $unit = CapacityUnit::tryFrom($unitRaw)
            ?? throw CapacityException::because("unidad de capacidad desconocida en la ventana: {$unitRaw}");
        if (!isset($raw['quantity']) || !is_numeric($raw['quantity'])) {
            throw CapacityException::because('la cantidad de la ventana debe ser numérica');
        }

        return new self(
            TimeSlot::fromString($timeSlot),
            $unit,
            Quantity::of((float) $raw['quantity']),
        );
    }

    /**
     * @return array{time_slot: string, unit: string, quantity: float}
     */
    public function toArray(): array
    {
        return [
            'time_slot' => $this->timeSlot->toString(),
            'unit' => $this->unit->value,
            'quantity' => $this->quantity->value(),
        ];
    }
}
