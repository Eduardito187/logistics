<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

use DateTimeInterface;
use Logistics\Execution\Application\Contract\StopEnrichmentReader;
use Logistics\Execution\Domain\Model\RouteStop;

/**
 * Modelo de lectura de una parada de la ruta del chofer: forma plana en primitivos para la app y el
 * panel. Lleva la foto legible del destino (pedido, cliente, dirección, ETA) y el estado de la parada.
 *
 * Además del dato que vive en el agregado, lleva campos ENRIQUECIDOS cross-context (viven en shipments):
 * el teléfono del cliente y la referencia de dirección (indicaciones de portón/piso) para que el chofer
 * contacte y ubique sin otra llamada, y las líneas del pedido (qué baja en esta parada, con miniatura).
 * El enriquecimiento lo resuelve el QueryHandler por lectura directa; el dominio (RouteStop) no lo conoce.
 *
 * `units_total` es la cantidad de BULTOS de la parada (cuenta de shipment_units), que es lo que el POD debe
 * esperar: NUNCA la suma de cantidades de producto. `arrived_at` es la primera llegada informada.
 *
 * @phpstan-import-type StopItem from StopEnrichmentReader
 * @phpstan-import-type StopDestination from StopEnrichmentReader
 */
final readonly class RouteStopView
{
    /**
     * @param list<StopItem>       $items
     * @param StopDestination|null $destination
     * @param list<string>         $unitIds     ids de los bultos esperados en esta parada (validación offline)
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
        public ?array $destination = null,
        public array $unitIds = [],
        public ?string $arrivedAt = null,
        public ?string $arrivalSource = null,
        // Prueba de entrega ya capturada (confirmación + foto), para que la app la muestre en la parada sin
        // pedir aparte GET /app/deliveries/{id}. Null mientras no haya POD.
        public ?string $podConfirmation = null,
        public ?string $podPhotoUrl = null,
    ) {}

    /**
     * Construye la vista desde el agregado, con el enriquecimiento cross-context opcional del envío.
     *
     * @param list<StopItem>       $items
     * @param StopDestination|null $destination
     * @param list<string>         $unitIds
     */
    public static function fromStop(
        RouteStop $stop,
        ?string $customerPhone = null,
        ?string $addressReference = null,
        array $items = [],
        ?array $destination = null,
        array $unitIds = [],
        ?string $podConfirmation = null,
        ?string $podPhotoUrl = null,
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
            $destination,
            $unitIds,
            $stop->arrivedAt()?->format(DateTimeInterface::ATOM),
            $stop->arrivalSource()?->value,
            $podConfirmation,
            $podPhotoUrl,
        );
    }

    /**
     * Bultos esperados en la parada (cuenta de bultos, no de productos).
     */
    public function unitsTotal(): int
    {
        return count($this->unitIds);
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
            'arrived_at' => $this->arrivedAt,
            'arrival_source' => $this->arrivalSource,
            'customer_phone' => $this->customerPhone,
            'address_reference' => $this->addressReference,
            'items' => $this->items,
            'unit_ids' => $this->unitIds,
            'units_total' => $this->unitsTotal(),
            'destination' => $this->destination,
            'pod_confirmation' => $this->podConfirmation,
            'pod_photo_url' => $this->podPhotoUrl,
        ];
    }
}
