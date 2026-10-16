<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

use DateTimeInterface;
use Logistics\Execution\Domain\Model\RouteStop;

/**
 * Modelo de lectura de una parada de la ruta del chofer: forma plana en primitivos para la app y el
 * panel. Lleva la foto legible del destino (pedido, cliente, dirección, ETA) y el estado de la parada.
 *
 * Además del dato que vive en el agregado, lleva campos ENRIQUECIDOS cross-context (viven en shipments):
 * el teléfono del cliente y la referencia de dirección (indicaciones de portón/piso) para que el chofer
 * contacte y ubique sin otra llamada, y las líneas del pedido (qué baja en esta parada, con miniatura).
 * El enriquecimiento lo resuelve el QueryHandler por lectura directa; el dominio (RouteStop) no lo conoce.
 */
final readonly class RouteStopView
{
    /**
     * @param list<array{sku: string, name: string, quantity: int, image_url: string|null}> $items
     */
    public function __construct(
        public string $id,
        public string $shipmentId,
        public int $sequence,
        public ?string $orderReference,
        public ?string $customerName,
        public ?string $address,
        public ?string $etaAt,
        public ?float $lat,
        public ?float $lng,
        public string $status,
        public ?string $settledAt,
        public ?string $customerPhone = null,
        public ?string $addressReference = null,
        public array $items = [],
    ) {}

    /**
     * Construye la vista desde el agregado, con el enriquecimiento cross-context opcional del envío.
     *
     * @param list<array{sku: string, name: string, quantity: int, image_url: string|null}> $items
     */
    public static function fromStop(
        RouteStop $stop,
        ?string $customerPhone = null,
        ?string $addressReference = null,
        array $items = [],
    ): self {
        return new self(
            $stop->id()->toString(),
            $stop->shipmentId(),
            $stop->sequence(),
            $stop->orderReference(),
            $stop->customerName(),
            $stop->address(),
            $stop->etaAt()?->format(DateTimeInterface::ATOM),
            $stop->lat(),
            $stop->lng(),
            $stop->status()->value,
            $stop->settledAt()?->format(DateTimeInterface::ATOM),
            $customerPhone,
            $addressReference,
            $items,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipmentId,
            'sequence' => $this->sequence,
            'order_reference' => $this->orderReference,
            'customer_name' => $this->customerName,
            'address' => $this->address,
            'eta_at' => $this->etaAt,
            'lat' => $this->lat,
            'lng' => $this->lng,
            'status' => $this->status,
            'settled_at' => $this->settledAt,
            'customer_phone' => $this->customerPhone,
            'address_reference' => $this->addressReference,
            'items' => $this->items,
        ];
    }
}
