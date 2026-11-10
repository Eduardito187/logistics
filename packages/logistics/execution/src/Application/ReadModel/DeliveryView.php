<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

use Logistics\Execution\Domain\Model\Delivery;

/**
 * Modelo de lectura de la entrega: forma plana en primitivos para APIs, listados y la app del chofer.
 *
 * Se arma desde el agregado con fromDelivery(); las consultas nunca exponen el objeto de dominio.
 */
final readonly class DeliveryView
{
    /**
     * @param list<ScanEventView> $scans
     */
    public function __construct(
        public string $id,
        public string $shipmentId,
        public string $vehicleId,
        public string $status,
        public ?string $podConfirmation,
        public ?string $podPhotoUrl,
        public ?string $podPhotoPath,
        public ?string $failureReason,
        public array $scans,
        // Referencia LEGIBLE del envío para el chofer (NUNCA el UUID): pedido, cliente y dirección.
        public ?string $orderReference = null,
        public ?string $customerName = null,
        public ?string $address = null,
    ) {}

    /**
     * @param array{order_reference: string|null, customer_name: string|null, address: string|null}|null $reference
     */
    public static function fromDelivery(Delivery $delivery, ?array $reference = null): self
    {
        $scans = [];
        foreach ($delivery->scans() as $scan) {
            $scans[] = ScanEventView::fromScanEvent($scan);
        }

        $proof = $delivery->proofOfDelivery();

        return new self(
            $delivery->id()->toString(),
            $delivery->shipmentId(),
            $delivery->vehicleId(),
            $delivery->status()->value,
            $proof?->confirmation,
            $proof?->photoUrl,
            $proof?->photoPath,
            $delivery->failureReason()?->value,
            $scans,
            $reference['order_reference'] ?? null,
            $reference['customer_name'] ?? null,
            $reference['address'] ?? null,
        );
    }

    /**
     * Etiqueta legible SIEMPRE presente para mostrar en lugar del UUID: pedido, si no cliente, si no un
     * texto neutro. Nunca null ni vacío (regla: el chofer jamás ve un UUID).
     */
    public function referenceLabel(): string
    {
        foreach ([$this->orderReference, $this->customerName] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return trim($candidate);
            }
        }

        return 'Envío sin referencia';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipmentId,
            'vehicle_id' => $this->vehicleId,
            'status' => $this->status,
            // Referencia legible del envío (NUNCA UUID): el cliente muestra `reference` / cliente / dirección.
            'reference' => $this->referenceLabel(),
            'order_reference' => $this->orderReference,
            'customer_name' => $this->customerName,
            'address' => $this->address,
            'pod_confirmation' => $this->podConfirmation,
            'pod_photo_url' => $this->podPhotoUrl,
            'pod_photo_path' => $this->podPhotoPath,
            'failure_reason' => $this->failureReason,
            'scans' => array_map(static fn (ScanEventView $scan): array => $scan->toArray(), $this->scans),
        ];
    }
}
