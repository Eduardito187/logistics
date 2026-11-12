<?php

declare(strict_types=1);

namespace Logistics\Labels\Application\ReadModel;

use Logistics\Labels\Application\Contract\LabelEnricher;
use Logistics\Labels\Domain\Model\EmittedLabel;

/**
 * Modelo de lectura de una etiqueta emitida: forma plana y primitiva, lista para la API o la app.
 *
 * `qrContent` es el JSON firmado exacto (lo que lleva el QR); `zpl` es lo que se manda a la impresora.
 * Si se cachea, se cachea su toArray() (regla 7 del proyecto), nunca el objeto de dominio.
 *
 * ENRIQUECIMIENTO (nombres humanos, CERO uuid): además de los datos de la etiqueta, la vista expone el
 * PRODUCTO (nombre + imagen servible, resueltos por SKU), el ORIGEN (código del QR + nombre de nodo si el
 * código matchea la red) y el DESTINO (tipo home|store|warehouse + nombre + dirección, resuelto desde el
 * envío de la orden). Esos campos los rellena un enricher cross-context en lectura (ver
 * {@see LabelEnricher}); sin enriquecer vienen en su valor base.
 */
final readonly class EmittedLabelView
{
    public function __construct(
        public string $orderNumber,
        public string $labelId,
        public int $keyId,
        public string $sku,
        public int $lineNumber,
        public string $qrContent,
        public string $zpl,
        public int $emittedAt,
        // Enriquecimiento (nombres humanos). Base (sin enriquecer): producto/destino en null, origen = solo código.
        public ?string $productName = null,
        public ?string $imageUrl = null,
        public string $originCode = '',
        public ?string $originName = null,
        public ?string $destinationType = null,
        public ?string $destinationName = null,
        public ?string $destinationAddress = null,
        // Bulto físico resuelto (offline-first: la app mapea label_id→unit_id localmente, sin red, para
        // escanear contra el bulto real). Base = null; los rellena el enricher.
        public ?string $unitId = null,
        public ?string $shipmentId = null,
        // Índices del bulto dentro de la línea (del payload del QR), para orden/correlación.
        public int $unitIndex = 1,
        public int $unitTotal = 1,
        public int $pieceIndex = 1,
        public int $pieceTotal = 1,
    ) {}

    public static function fromEmittedLabel(EmittedLabel $label): self
    {
        return new self(
            orderNumber: $label->orderNumber,
            labelId: $label->labelId(),
            keyId: $label->keyId(),
            sku: $label->sku(),
            lineNumber: $label->lineNumber(),
            qrContent: $label->signedJson(),
            zpl: $label->zpl,
            emittedAt: $label->emittedAt,
            originCode: $label->originCode(),
            unitIndex: $label->unitIndex(),
            unitTotal: $label->unitTotal(),
            pieceIndex: $label->pieceIndex(),
            pieceTotal: $label->pieceTotal(),
        );
    }

    /**
     * Devuelve una copia con los datos humanos resueltos (producto, origen, destino). Los campos base de la
     * etiqueta no cambian. `imageUrl` debe venir como URL servible absoluta.
     *
     * @param array{type: string|null, name: string|null, address: string|null} $destination
     */
    public function enriched(
        ?string $productName,
        ?string $imageUrl,
        ?string $originName,
        array $destination,
        ?string $unitId = null,
        ?string $shipmentId = null,
    ): self {
        return new self(
            orderNumber: $this->orderNumber,
            labelId: $this->labelId,
            keyId: $this->keyId,
            sku: $this->sku,
            lineNumber: $this->lineNumber,
            qrContent: $this->qrContent,
            zpl: $this->zpl,
            emittedAt: $this->emittedAt,
            productName: $productName,
            imageUrl: $imageUrl,
            originCode: $this->originCode,
            originName: $originName,
            destinationType: $destination['type'] ?? null,
            destinationName: $destination['name'] ?? null,
            destinationAddress: $destination['address'] ?? null,
            unitId: $unitId,
            shipmentId: $shipmentId,
            unitIndex: $this->unitIndex,
            unitTotal: $this->unitTotal,
            pieceIndex: $this->pieceIndex,
            pieceTotal: $this->pieceTotal,
        );
    }

    /**
     * @return array{
     *   order_number: string, label_id: string, key_id: int, sku: string, line_number: int,
     *   qr_content: string, zpl: string, emitted_at: int, product_name: string|null, image_url: string|null,
     *   origin: array{code: string, name: string|null},
     *   destination: array{type: string|null, name: string|null, address: string|null},
     *   unit_id: string|null, shipment_id: string|null,
     *   unit: array{index: int, total: int}, piece: array{index: int, total: int}
     * }
     */
    public function toArray(): array
    {
        return [
            'order_number' => $this->orderNumber,
            'label_id' => $this->labelId,
            'key_id' => $this->keyId,
            'sku' => $this->sku,
            'line_number' => $this->lineNumber,
            'qr_content' => $this->qrContent,
            'zpl' => $this->zpl,
            'emitted_at' => $this->emittedAt,
            'product_name' => $this->productName,
            'image_url' => $this->imageUrl,
            'origin' => [
                'code' => $this->originCode,
                'name' => $this->originName,
            ],
            'destination' => [
                'type' => $this->destinationType,
                'name' => $this->destinationName,
                'address' => $this->destinationAddress,
            ],
            // Bulto físico real (offline-first): la app mapea label_id→unit_id localmente para el escaneo.
            'unit_id' => $this->unitId,
            'shipment_id' => $this->shipmentId,
            'unit' => ['index' => $this->unitIndex, 'total' => $this->unitTotal],
            'piece' => ['index' => $this->pieceIndex, 'total' => $this->pieceTotal],
        ];
    }
}
