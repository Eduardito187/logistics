<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

use DateTimeInterface;
use Logistics\Execution\Domain\Model\RouteStop;

/**
 * Modelo de lectura de una parada de la ruta del chofer: forma plana en primitivos para la app y el
 * panel. Lleva la foto legible del destino (pedido, cliente, dirección, ETA) y el estado de la parada.
 */
final readonly class RouteStopView
{
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
    ) {}

    public static function fromStop(RouteStop $stop): self
    {
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
        ];
    }
}
