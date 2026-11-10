<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\ReadModel;

/**
 * Una LÍNEA DE DESPACHO de una orden para la pantalla de etiquetado por producto: producto (nombre +
 * imagen servible), cantidad y bultos por unidad, origen y destino humanos (cero uuid), el envío al que
 * pertenece (shipment_id opaco para agrupar + shipment_reference legible), si está AUTORIZADA (plan
 * publicado) y el estado de su etiqueta.
 */
final readonly class DispatchLineView
{
    public function __construct(
        public string $sku,
        public ?string $productName,
        public ?string $imageUrl,
        public int $quantity,
        public int $piecesPerUnit,
        public int $lineNumber,
        public string $originCode,
        public ?string $originName,
        public ?string $destinationType,
        public ?string $destinationName,
        public ?string $destinationAddress,
        public ?string $shipmentId,
        public ?string $shipmentReference,
        public bool $authorized,
        public ?string $authorizedReason,
        public string $labelStatus,
    ) {}

    /**
     * @return array{
     *   sku: string, product_name: string|null, image_url: string|null, quantity: int,
     *   pieces_per_unit: int, line_number: int,
     *   origin: array{code: string, name: string|null},
     *   destination: array{type: string|null, name: string|null, address: string|null},
     *   shipment_id: string|null, shipment_reference: string|null,
     *   authorized: bool, authorized_reason: string|null, label_status: string
     * }
     */
    public function toArray(): array
    {
        return [
            'sku' => $this->sku,
            'product_name' => $this->productName,
            'image_url' => $this->imageUrl,
            'quantity' => $this->quantity,
            'pieces_per_unit' => $this->piecesPerUnit,
            'line_number' => $this->lineNumber,
            'origin' => [
                'code' => $this->originCode,
                'name' => $this->originName,
            ],
            'destination' => [
                'type' => $this->destinationType,
                'name' => $this->destinationName,
                'address' => $this->destinationAddress,
            ],
            'shipment_id' => $this->shipmentId,
            'shipment_reference' => $this->shipmentReference,
            'authorized' => $this->authorized,
            'authorized_reason' => $this->authorizedReason,
            'label_status' => $this->labelStatus,
        ];
    }
}
