<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Persistence\Eloquent;

use Logistics\Shared\Domain\ValueObject\Ownership;
use Logistics\Shipments\Domain\Model\PhysicalUnit;
use Logistics\Shipments\Domain\Model\Shipment;
use Logistics\Shipments\Domain\Model\ShipmentLine;
use Logistics\Shipments\Domain\ValueObject\Address;
use Logistics\Shipments\Domain\ValueObject\AddressConfidence;
use Logistics\Shipments\Domain\ValueObject\Customer;
use Logistics\Shipments\Domain\ValueObject\DeliverySlot;
use Logistics\Shipments\Domain\ValueObject\Fraction;
use Logistics\Shipments\Domain\ValueObject\GeoPoint;
use Logistics\Shipments\Domain\ValueObject\Money;
use Logistics\Shipments\Domain\ValueObject\OrderSource;
use Logistics\Shipments\Domain\ValueObject\PhysicalUnitId;
use Logistics\Shipments\Domain\ValueObject\ProductImageUrl;
use Logistics\Shipments\Domain\ValueObject\ProductName;
use Logistics\Shipments\Domain\ValueObject\ShipmentId;
use Logistics\Shipments\Domain\ValueObject\ShipmentLineId;
use Logistics\Shipments\Domain\ValueObject\ShipmentStatus;
use Logistics\Shipments\Domain\ValueObject\Sku;
use Logistics\Shipments\Domain\ValueObject\SourceReference;

/**
 * Traduce entre el agregado Shipment y sus filas Eloquent. Toda la conversión a/desde primitivos vive
 * acá, para que ni el dominio ni el repositorio conozcan la forma de las tablas.
 */
final class ShipmentMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toShipmentRow(Shipment $shipment): array
    {
        $geoPoint = $shipment->address()->geoPoint;
        $deliverySlot = $shipment->deliverySlot();
        $ownership = $shipment->ownership()->toArray();

        return [
            'id' => $shipment->id()->toString(),
            'owner_party_id' => $ownership['owner_party_id'],
            'fulfillment_mode' => $ownership['mode'],
            'seller_sku_ref' => $ownership['seller_sku_ref'],
            'source' => $shipment->source()->value,
            'external_id' => $shipment->sourceReference()->externalId,
            'customer_name' => $shipment->customer()->name,
            'customer_document_id' => $shipment->customer()->documentId,
            'customer_phone' => $shipment->customer()->phone,
            'customer_email' => $shipment->customer()->email,
            'address_text' => $shipment->address()->text,
            'address_reference' => $shipment->address()->reference,
            'lat' => $geoPoint?->lat,
            'lng' => $geoPoint?->lng,
            'address_confidence' => $shipment->addressConfidence()->score,
            'address_confidence_level' => $shipment->addressConfidence()->level->value,
            'status' => $shipment->status()->value,
            'destination_node_id' => $shipment->destinationNodeId(),
            'delivery_date' => $deliverySlot?->date,
            'delivery_window_start' => $deliverySlot?->windowStart,
            'delivery_window_end' => $deliverySlot?->windowEnd,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toLineRow(Shipment $shipment, ShipmentLine $line): array
    {
        $unitPrice = $line->unitPrice();
        $imageUrl = $line->imageUrl();

        return [
            'id' => $line->id()->toString(),
            'shipment_id' => $shipment->id()->toString(),
            'sku' => (string) $line->sku(),
            'product_name' => (string) $line->productName(),
            'product_image_url' => $imageUrl !== null ? $imageUrl->value : null,
            'unit_price' => $unitPrice?->amount,
            'unit_price_currency' => $unitPrice?->currency,
            'quantity' => $line->quantity(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toUnitRow(Shipment $shipment, PhysicalUnit $unit): array
    {
        $ownership = $unit->ownership()->toArray();

        return [
            'id' => $unit->id()->toString(),
            'shipment_id' => $shipment->id()->toString(),
            'line_id' => $unit->lineId()->toString(),
            'owner_party_id' => $ownership['owner_party_id'],
            'fulfillment_mode' => $ownership['mode'],
            'seller_sku_ref' => $ownership['seller_sku_ref'],
            'unit_index' => $unit->unit()->index,
            'unit_total' => $unit->unit()->total,
            'piece_index' => $unit->piece()->index,
            'piece_total' => $unit->piece()->total,
        ];
    }

    /**
     * Reconstruye el agregado completo (líneas y bultos) desde sus filas.
     */
    public function toDomain(ShipmentRecord $record): Shipment
    {
        $source = OrderSource::from($record->source);

        $geoPoint = $record->lat !== null && $record->lng !== null
            ? GeoPoint::of((float) $record->lat, (float) $record->lng)
            : null;

        $address = Address::of($record->address_text, $record->address_reference, $geoPoint);

        $lines = [];
        foreach ($record->lines as $lineRecord) {
            $unitPrice = $lineRecord->unit_price !== null
                ? Money::of(
                    (float) $lineRecord->unit_price,
                    $lineRecord->unit_price_currency ?? 'BOB',
                )
                : null;

            $lines[] = ShipmentLine::create(
                ShipmentLineId::fromString($lineRecord->id),
                Sku::of($lineRecord->sku),
                ProductName::of($lineRecord->product_name),
                $lineRecord->quantity,
                ProductImageUrl::fromNullable($lineRecord->product_image_url),
                $unitPrice,
            );
        }

        $units = [];
        foreach ($record->units as $unitRecord) {
            $units[] = PhysicalUnit::create(
                PhysicalUnitId::fromString($unitRecord->id),
                ShipmentLineId::fromString($unitRecord->line_id),
                Fraction::of($unitRecord->unit_index, $unitRecord->unit_total),
                Fraction::of($unitRecord->piece_index, $unitRecord->piece_total),
                // Filas viejas (mode 'owned', dueño null) reconstituyen a dismacOwned() por el default.
                Ownership::fromPrimitives(
                    $unitRecord->fulfillment_mode,
                    $unitRecord->owner_party_id,
                    $unitRecord->seller_sku_ref,
                ),
            );
        }

        $customer = Customer::of(
            $record->customer_name,
            $record->customer_document_id,
            $record->customer_phone,
            $record->customer_email,
        );

        $deliverySlot = DeliverySlot::fromNullable(
            $record->delivery_date,
            $record->delivery_window_start,
            $record->delivery_window_end,
        );

        return Shipment::reconstitute(
            ShipmentId::fromString($record->id),
            $source,
            SourceReference::of($source, $record->external_id),
            $customer,
            $address,
            AddressConfidence::of($record->address_confidence),
            ShipmentStatus::from($record->status),
            $lines,
            $units,
            $deliverySlot,
            $record->destination_node_id,
            // Filas viejas (mode 'owned', dueño null) reconstituyen a dismacOwned() por el default.
            Ownership::fromPrimitives(
                $record->fulfillment_mode,
                $record->owner_party_id,
                $record->seller_sku_ref,
            ),
        );
    }
}
