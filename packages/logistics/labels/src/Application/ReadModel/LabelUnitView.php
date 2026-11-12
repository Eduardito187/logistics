<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\ReadModel;

/**
 * Resolución de una etiqueta escaneada (su `id`/ULID del QR) al BULTO FÍSICO (PhysicalUnit, UUID de
 * shipments) y su envío. Cierra el hueco etiqueta→bulto: la app escanea el QR, resuelve acá el
 * unit_id/shipment_id REALES y recién con esos llama a los endpoints de escaneo (así el escaneo se ata al
 * bulto y el tracking cierra). La correlación es determinista por (orden→envío, línea, índice de unidad e
 * índice de pieza). `resolved=false` si no se pudo atar (etiqueta inexistente, sin envío o sin bulto).
 */
final readonly class LabelUnitView
{
    public function __construct(
        public string $labelId,
        public bool $resolved,
        public ?string $unitId,
        public ?string $shipmentId,
        public ?string $sku,
        public ?string $productName,
        public ?int $lineNumber,
        public ?int $unitIndex,
        public ?int $unitTotal,
        public ?int $pieceIndex,
        public ?int $pieceTotal,
        // `orderNumber` → contexto/validación "pertenece a este pedido". Las etiquetas son PERMANENTES:
        // no existe el concepto de etiqueta anulada. Default para no romper construcciones posicionales.
        public ?string $orderNumber = null,
    ) {}

    /**
     * @return array{label_id: string, resolved: bool, order_number: string|null, unit_id: string|null, shipment_id: string|null, sku: string|null, product_name: string|null, line_number: int|null, unit: array{index: int, total: int}|null, piece: array{index: int, total: int}|null}
     */
    public function toArray(): array
    {
        return [
            'label_id' => $this->labelId,
            'resolved' => $this->resolved,
            'order_number' => $this->orderNumber,
            'unit_id' => $this->unitId,
            'shipment_id' => $this->shipmentId,
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'line_number' => $this->lineNumber,
            'unit' => $this->unitIndex !== null && $this->unitTotal !== null
                ? ['index' => $this->unitIndex, 'total' => $this->unitTotal]
                : null,
            'piece' => $this->pieceIndex !== null && $this->pieceTotal !== null
                ? ['index' => $this->pieceIndex, 'total' => $this->pieceTotal]
                : null,
        ];
    }
}
