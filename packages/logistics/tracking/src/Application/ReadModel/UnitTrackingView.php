<?php

declare(strict_types=1);

namespace Logistics\Tracking\Application\ReadModel;

use Logistics\Tracking\Domain\Model\UnitTracking;

/**
 * Modelo de lectura INTERNO del seguimiento de un bulto, para back office y la app interna.
 *
 * Incluye las claves internas (id de seguimiento del bulto, unit_id) y la etiqueta humana del bulto
 * ("unidad N de M", "pieza N de M") resuelta en el QueryHandler desde shipments, junto a la última
 * ubicación y el instante del último escaneo. Si se cachea, se cachea su toArray() (regla 7).
 */
final readonly class UnitTrackingView
{
    public function __construct(
        public string $id,
        public string $unitId,
        public string $shipmentId,
        public string $status,
        public ?string $lastLocationId,
        public ?string $lastSeenAt,
        // Etiqueta humana del bulto resuelta desde shipment_units (lado lectura). Null si el bulto ya
        // no existe en shipments; el uuid crudo se mantiene en unit_id.
        public ?string $label = null,
        // Producto del bulto (shipment_lines) y su pieza, para el recojo en mostrador. Null si no se resuelve.
        public ?string $sku = null,
        public ?string $productName = null,
        public ?string $productImageUrl = null,
        public ?int $pieceIndex = null,
        public ?int $pieceTotal = null,
    ) {}

    /**
     * @param array{sku?: string|null, product_name?: string|null, product_image_url?: string|null, piece_index?: int|null, piece_total?: int|null} $product
     */
    public static function fromUnit(UnitTracking $unit, ?string $label = null, array $product = []): self
    {
        return new self(
            $unit->id()->toString(),
            $unit->unitId(),
            $unit->shipmentId(),
            $unit->status()->value,
            $unit->lastLocationId(),
            $unit->lastSeenAt()?->format(DATE_ATOM),
            $label,
            $product['sku'] ?? null,
            $product['product_name'] ?? null,
            $product['product_image_url'] ?? null,
            $product['piece_index'] ?? null,
            $product['piece_total'] ?? null,
        );
    }

    /**
     * @return array{id: string, unit_id: string, shipment_id: string, status: string, last_location_id: string|null, last_seen_at: string|null, label: string|null, sku: string|null, product_name: string|null, product_image_url: string|null, piece_index: int|null, piece_total: int|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'unit_id' => $this->unitId,
            'shipment_id' => $this->shipmentId,
            'status' => $this->status,
            'last_location_id' => $this->lastLocationId,
            'last_seen_at' => $this->lastSeenAt,
            'label' => $this->label,
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'product_image_url' => $this->productImageUrl,
            'piece_index' => $this->pieceIndex,
            'piece_total' => $this->pieceTotal,
        ];
    }
}
