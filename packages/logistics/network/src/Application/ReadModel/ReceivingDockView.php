<?php

declare(strict_types=1);

namespace Logistics\Network\Application\ReadModel;

use Logistics\Network\Domain\ReceivingDock;

/**
 * Modelo de lectura de un andén de recepción: forma plana y primitiva, lista para serializar a JSON o
 * para la API. No expone objetos de dominio; si se cachea, se cachea su toArray() (regla 7).
 */
final readonly class ReceivingDockView
{
    public function __construct(
        public string $id,
        public string $warehouseId,
        public string $code,
        public string $name,
        public int $appointmentsPerSlot,
        public bool $active,
    ) {}

    public static function fromDock(ReceivingDock $dock): self
    {
        return new self(
            $dock->id()->toString(),
            $dock->warehouseId()->toString(),
            $dock->code()->toString(),
            $dock->name(),
            $dock->capacity()->appointmentsPerSlot,
            $dock->isActive(),
        );
    }

    /**
     * @return array{id: string, warehouse_id: string, code: string, name: string, appointments_per_slot: int, active: bool}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'warehouse_id' => $this->warehouseId,
            'code' => $this->code,
            'name' => $this->name,
            'appointments_per_slot' => $this->appointmentsPerSlot,
            'active' => $this->active,
        ];
    }
}
