<?php

declare(strict_types=1);

namespace Logistics\Capacity\Application\ReadModel;

use Logistics\Capacity\Domain\ValueObject\ActivationWindow;
use Logistics\Capacity\Domain\ValueObject\CapacityRuleset;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;
use Logistics\Capacity\Domain\ValueObject\TimeSlot;

/**
 * Modelo de lectura del catálogo de activación de cupos: lo que necesita un cliente (la app, el admin)
 * para armar una activación por rango sin adivinar valores.
 *
 *  - `timeSlots`: franjas 'HH:MM-HH:MM' que las reglas ofrecen (por defecto + overrides por día).
 *  - `units`: unidades que acepta una ventana, con su etiqueta legible (en inglés, la traduce Presentation).
 *  - `defaultWindows`: ventanas por defecto {time_slot, unit, quantity}, listas para mandarlas tal cual
 *    a la activación. Vacío = las reglas no definen ventanas por defecto.
 *  - `weekdays`: días de la semana admitidos (1 = lunes … 7 = domingo).
 *  - `serviceDays`: días de servicio de las reglas (sugerencia para preseleccionar).
 */
final readonly class ActivationOptionsView
{
    /**
     * @param list<string>                                                  $timeSlots
     * @param list<array{value: string, label: string}>                     $units
     * @param list<array{time_slot: string, unit: string, quantity: float}> $defaultWindows
     * @param list<int>                                                     $weekdays
     * @param list<int>                                                     $serviceDays
     */
    public function __construct(
        public array $timeSlots,
        public array $units,
        public array $defaultWindows,
        public array $weekdays,
        public array $serviceDays,
    ) {}

    public static function fromRuleset(CapacityRuleset $ruleset): self
    {
        return new self(
            timeSlots: array_map(static fn (TimeSlot $slot): string => $slot->toString(), $ruleset->offeredWindows()),
            units: array_map(
                static fn (CapacityUnit $unit): array => ['value' => $unit->value, 'label' => $unit->label()],
                CapacityUnit::cases(),
            ),
            defaultWindows: array_map(
                static fn (ActivationWindow $window): array => $window->toArray(),
                $ruleset->defaultActivationWindows(),
            ),
            weekdays: [1, 2, 3, 4, 5, 6, 7],
            serviceDays: $ruleset->serviceDays(),
        );
    }

    public function hasDefaultWindows(): bool
    {
        return $this->defaultWindows !== [];
    }

    /**
     * @return array{time_slots: list<string>, units: list<array{value: string, label: string}>, default_windows: list<array{time_slot: string, unit: string, quantity: float}>, weekdays: list<int>, service_days: list<int>}
     */
    public function toArray(): array
    {
        return [
            'time_slots' => $this->timeSlots,
            'units' => $this->units,
            'default_windows' => $this->defaultWindows,
            'weekdays' => $this->weekdays,
            'service_days' => $this->serviceDays,
        ];
    }
}
