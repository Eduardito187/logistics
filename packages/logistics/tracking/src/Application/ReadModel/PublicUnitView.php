<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\ReadModel;

use Logistics\Tracking\Domain\Model\UnitTracking;
use Logistics\Tracking\Domain\ValueObject\UnitStatus;

/**
 * Modelo de lectura PÚBLICO del detalle de un bulto (acceso por token).
 *
 * El cliente ve el desglose de sus bultos ("ropero 1 de 2: cargado") sin claves internas: solo la
 * etiqueta humana del bulto, su estado y un mensaje legible en español. NO expone el uuid del bulto,
 * el del envío ni el nodo interno. Si se cachea, se cachea su toArray() (regla 7).
 */
final readonly class PublicUnitView
{
    public function __construct(
        public string $label,
        public string $status,
        public string $statusMessage,
    ) {}

    public static function fromUnit(UnitTracking $unit, string $label): self
    {
        return new self(
            $label,
            $unit->status()->value,
            self::messageFor($unit->status()),
        );
    }

    /**
     * Mensaje al cliente por bulto, en español (copy de cliente, no texto de admin).
     */
    private static function messageFor(UnitStatus $status): string
    {
        return match ($status) {
            UnitStatus::Registered => 'Registrado.',
            UnitStatus::Prepared => 'Preparado en bodega.',
            UnitStatus::Loaded => 'Cargado en el camión.',
            UnitStatus::Unloaded => 'Descargado en una parada.',
            UnitStatus::Received => 'Recibido en el punto logístico.',
            UnitStatus::Delivered => 'Entregado.',
            UnitStatus::Failed => 'No se pudo entregar.',
        };
    }

    /**
     * @return array{label: string, status: string, status_message: string}
     */
    public function toArray(): array
    {
        return [
            'label' => $this->label,
            'status' => $this->status,
            'status_message' => $this->statusMessage,
        ];
    }
}
