<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\ReadModel;

/**
 * Available-to-Promise de un almacén/sku (opcionalmente por dueño): stock disponible, no reservado, que
 * el motor de promesa (`promise`) puede comprometer. Lectura optimizada para el lado de consulta.
 */
final readonly class AvailableToPromiseView
{
    public function __construct(
        public string $warehouseId,
        public string $sku,
        public ?string $ownerPartyId,
        public int $availableToPromise,
    ) {}

    /**
     * @return array{warehouse_id: string, sku: string, owner_party_id: ?string, available_to_promise: int}
     */
    public function toArray(): array
    {
        return [
            'warehouse_id' => $this->warehouseId,
            'sku' => $this->sku,
            'owner_party_id' => $this->ownerPartyId,
            'available_to_promise' => $this->availableToPromise,
        ];
    }
}
