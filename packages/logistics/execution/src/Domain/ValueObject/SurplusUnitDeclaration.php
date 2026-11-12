<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\ValueObject;

use Logistics\Execution\Domain\Exception\ExecutionException;

/**
 * Declaración de un producto sobrante en la conciliación: qué producto llegó sin esperarse y, si el
 * operador lo sabe, a qué envío pertenece en realidad (para devolverlo o redirigirlo).
 */
final readonly class SurplusUnitDeclaration
{
    private function __construct(
        public string $unitId,
        public ?string $belongsToShipmentId,
    ) {}

    public static function of(string $unitId, ?string $belongsToShipmentId = null): self
    {
        $unit = trim($unitId);
        if ($unit === '') {
            throw ExecutionException::because('la declaración de un sobrante requiere el id del producto');
        }

        $belongs = $belongsToShipmentId === null ? null : trim($belongsToShipmentId);

        return new self($unit, $belongs === '' ? null : $belongs);
    }

    /**
     * @return array{unit_id: string, belongs_to_shipment_id: string|null}
     */
    public function toArray(): array
    {
        return ['unit_id' => $this->unitId, 'belongs_to_shipment_id' => $this->belongsToShipmentId];
    }
}
