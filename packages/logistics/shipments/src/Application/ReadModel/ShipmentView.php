<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

use Logistics\Shipments\Domain\Model\Shipment;

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
        public string $addressText,
        public ?string $addressReference,
        public ?float $lat,
        public ?float $lng,
        public int $addressConfidenceScore,
        public string $addressConfidenceLevel,
        public string $status,
        public array $lines,
    ) {}

    public static function fromShipment(Shipment $shipment): self
    {
        $geoPoint = $shipment->address()->geoPoint;

        $lines = [];
        foreach ($shipment->lines() as $line) {
            $lines[] = new ShipmentLineView(
                $line->id()->toString(),
                (string) $line->sku(),
                (string) $line->productName(),
                $line->quantity(),
            );
        }

        return new self(
            $shipment->id()->toString(),
            $shipment->source()->value,
            $shipment->sourceReference()->externalId,
            $shipment->customerName(),
            $shipment->address()->text,
            $shipment->address()->reference,
            $geoPoint?->lat,
            $geoPoint?->lng,
            $shipment->addressConfidence()->score,
            $shipment->addressConfidence()->level->value,
            $shipment->status()->value,
            $lines,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'source' => $this->source,
            'external_id' => $this->externalId,
            'customer_name' => $this->customerName,
            'address_text' => $this->addressText,
            'address_reference' => $this->addressReference,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'address_confidence_score' => $this->addressConfidenceScore,
            'address_confidence_level' => $this->addressConfidenceLevel,
            'status' => $this->status,
            'lines' => array_map(static fn (ShipmentLineView $line): array => $line->toArray(), $this->lines),
        ];
    }
}
