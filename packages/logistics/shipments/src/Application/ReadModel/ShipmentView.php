<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\ValueObject\DeliveryMode;

/**
 * Modelo de lectura del envío: forma plana en primitivos para APIs, listados y paneles.
 *
 * Se arma desde el agregado con fromShipment(); las consultas nunca exponen el objeto de dominio.
 */
final readonly class ShipmentView
{
    /**
     * @param list<ShipmentLineView> $lines
     */
    public function __construct(
        public string $id,
        public string $source,
        public string $externalId,
        public string $customerName,
        public ?string $customerDocumentId,
        public ?string $customerPhone,
        public ?string $customerEmail,
        public string $addressText,
        public ?string $addressReference,
        public ?float $lat,
        public ?float $lng,
        public int $addressConfidenceScore,
        public string $addressConfidenceLevel,
        public string $status,
        public ?string $deliveryDate,
        public ?string $deliveryWindowStart,
        public ?string $deliveryWindowEnd,
        public ?string $destinationNodeId,
        public array $lines,
        // Avance operativo (camión, preparado, cargado, entregado, etiquetas): enriquecimiento del lado de
        // lectura que agrega el listado de la app. Null = no enriquecido (no se serializa).
        public ?ShipmentProgressView $progress = null,
        // Modalidad de entrega (home_delivery / store_pickup), DERIVADA del tipo del nodo destino por el
        // lado de lectura (ver DeliveryMode). Null = no enriquecida: toArray() la infiere solo cuando no
        // hay nodo destino (domicilio seguro); con nodo y sin enriquecer se serializa null.
        public ?string $deliveryMode = null,
    ) {}

    /**
     * Copia de la vista con su avance operativo adjunto (la vista es inmutable).
     */
    public function withProgress(ShipmentProgressView $progress): self
    {
        return $this->copy($progress, $this->deliveryMode);
    }

    /**
     * Copia de la vista con su modalidad de entrega resuelta (la vista es inmutable).
     */
    public function withDeliveryMode(DeliveryMode $deliveryMode): self
    {
        return $this->copy($this->progress, $deliveryMode->value);
    }

    private function copy(?ShipmentProgressView $progress, ?string $deliveryMode): self
    {
        return new self(
            $this->id,
            $this->source,
            $this->externalId,
            $this->customerName,
            $this->customerDocumentId,
            $this->customerPhone,
            $this->customerEmail,
            $this->addressText,
            $this->addressReference,
            $this->lat,
            $this->lng,
            $this->addressConfidenceScore,
            $this->addressConfidenceLevel,
            $this->status,
            $this->deliveryDate,
            $this->deliveryWindowStart,
            $this->deliveryWindowEnd,
            $this->destinationNodeId,
            $this->lines,
            $progress,
            $deliveryMode,
        );
    }

    public static function fromShipment(Shipment $shipment): self
    {
        $geoPoint = $shipment->address()->geoPoint;

        $lines = [];
        foreach ($shipment->lines() as $line) {
            $unitPrice = $line->unitPrice();
            $imageUrl = $line->imageUrl();
            $lines[] = new ShipmentLineView(
                $line->id()->toString(),
                (string) $line->sku(),
                (string) $line->productName(),
                $line->quantity(),
                $imageUrl !== null ? $imageUrl->value : null,
                $unitPrice?->amount,
                $unitPrice?->currency,
            );
        }

        $customer = $shipment->customer();
        $deliverySlot = $shipment->deliverySlot();

        return new self(
            $shipment->id()->toString(),
            $shipment->source()->value,
            $shipment->sourceReference()->externalId,
            $customer->name,
            $customer->documentId,
            $customer->phone,
            $customer->email,
            $shipment->address()->text,
            $shipment->address()->reference,
            $geoPoint?->lat,
            $geoPoint?->lng,
            $shipment->addressConfidence()->score,
            $shipment->addressConfidence()->level->value,
            $shipment->status()->value,
            $deliverySlot?->date,
            $deliverySlot?->windowStart,
            $deliverySlot?->windowEnd,
            $shipment->destinationNodeId(),
            $lines,
        );
    }

    /**
     * Construye el ReadModel directamente desde primitivos (lado de lectura CQRS), sin pasar por el
     * agregado. Aplica las MISMAS invariantes de forma que fromShipment() para que el resultado sea
     * idéntico byte a byte:
     *
     *  - lat/lng van juntos: si falta cualquiera de los dos, ambos quedan null (no hay punto a medias),
     *    igual que cuando el agregado no tiene GeoPoint.
     *  - la franja de entrega es excluyente: o están las tres columnas o ninguna (las tres null);
     *    la fecha se normaliza a 'Y-m-d' (10 chars) como DeliverySlot.
     *
     * Los demás campos se persisten ya normalizados por el agregado al guardar, así que leerlos crudos
     * reproduce la vista sin reconstruir los value objects.
     *
     * @param list<ShipmentLineView> $lines
     */
    public static function fromPrimitives(
        string $id,
        string $source,
        string $externalId,
        string $customerName,
        ?string $customerDocumentId,
        ?string $customerPhone,
        ?string $customerEmail,
        string $addressText,
        ?string $addressReference,
        ?float $lat,
        ?float $lng,
        int $addressConfidenceScore,
        string $addressConfidenceLevel,
        string $status,
        ?string $deliveryDate,
        ?string $deliveryWindowStart,
        ?string $deliveryWindowEnd,
        ?string $destinationNodeId,
        array $lines,
    ): self {
        // lat/lng van juntos: sin el par completo no hay coordenada.
        if ($lat === null || $lng === null) {
            $lat = null;
            $lng = null;
        }

        // Franja de entrega: las tres columnas o ninguna; la fecha se recorta a 'Y-m-d'.
        if ($deliveryDate === null || $deliveryWindowStart === null || $deliveryWindowEnd === null) {
            $deliveryDate = null;
            $deliveryWindowStart = null;
            $deliveryWindowEnd = null;
        } else {
            $deliveryDate = substr($deliveryDate, 0, 10);
        }

        return new self(
            $id,
            $source,
            $externalId,
            $customerName,
            $customerDocumentId,
            $customerPhone,
            $customerEmail,
            $addressText,
            $addressReference,
            $lat,
            $lng,
            $addressConfidenceScore,
            $addressConfidenceLevel,
            $status,
            $deliveryDate,
            $deliveryWindowStart,
            $deliveryWindowEnd,
            $destinationNodeId,
            $lines,
        );
    }

    /**
     * Dirección estructurada (best-effort) a partir del texto libre y la referencia.
     */
    public function addressParts(): AddressPartsView
    {
        return AddressPartsView::fromText($this->addressText, $this->addressReference);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $addressParts = $this->addressParts();

        $data = [
            'id' => $this->id,
            'source' => $this->source,
            'external_id' => $this->externalId,
            'customer_name' => $this->customerName,
            'customer_document_id' => $this->customerDocumentId,
            'customer_phone' => $this->customerPhone,
            'customer_email' => $this->customerEmail,
            'address_text' => $this->addressText,
            'address_reference' => $this->addressReference,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'address_confidence_score' => $this->addressConfidenceScore,
            'address_confidence_level' => $this->addressConfidenceLevel,
            'status' => $this->status,
            'delivery_date' => $this->deliveryDate,
            'delivery_window_start' => $this->deliveryWindowStart,
            'delivery_window_end' => $this->deliveryWindowEnd,
            'destination_node_id' => $this->destinationNodeId,
            'lines' => array_map(static fn (ShipmentLineView $line): array => $line->toArray(), $this->lines),
            // Aditivos (pedidos #22 y #26 de la app): modalidad de entrega y dirección estructurada.
            'delivery_mode' => $this->deliveryMode ?? ($this->destinationNodeId === null ? DeliveryMode::HomeDelivery->value : null),
            'address_parts' => $addressParts->toArray(),
            'short_address' => $addressParts->shortAddress,
        ];

        // Aditivo: solo cuando la consulta lo enriqueció (listado de la app), para no cambiar las demás
        // respuestas que comparten esta vista.
        if ($this->progress !== null) {
            $data['progress'] = $this->progress->toArray();
        }

        return $data;
    }
}
