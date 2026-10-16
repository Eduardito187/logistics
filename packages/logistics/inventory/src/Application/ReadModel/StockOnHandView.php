<?php

declare(strict_types=1);

namespace Logistics\Inventory\Application\ReadModel;

/**
 * Stock on-hand de un almacén/sku AGREGADO por dueño: una fila por (modo de fulfillment, dueño). Permite
 * ver, sin mezclar titularidad, cuánto hay propio de Dismac y cuánto en consignación de cada seller.
 */
final readonly class StockOnHandView
{
    public function __construct(
        public string $fulfillmentMode,
        public ?string $ownerPartyId,
        public int $available,
        public int $reserved,
        public int $onHand,
        public int $lots,
    ) {}

    /**
     * @param array{fulfillment_mode: string, owner_party_id: ?string, available: int, reserved: int, on_hand: int, lots: int} $row
     */
    public static function fromRow(array $row): self
    {
        return new self(
            $row['fulfillment_mode'],
            $row['owner_party_id'],
            $row['available'],
            $row['reserved'],
            $row['on_hand'],
            $row['lots'],
        );
    }

    /**
     * @return array{fulfillment_mode: string, owner_party_id: ?string, available: int, reserved: int, on_hand: int, lots: int}
     */
    public function toArray(): array
    {
        return [
            'fulfillment_mode' => $this->fulfillmentMode,
            'owner_party_id' => $this->ownerPartyId,
            'available' => $this->available,
            'reserved' => $this->reserved,
            'on_hand' => $this->onHand,
            'lots' => $this->lots,
        ];
    }
}
