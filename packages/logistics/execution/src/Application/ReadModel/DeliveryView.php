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
        public ?string $failureReason,
        public array $scans,
    ) {}

    public static function fromDelivery(Delivery $delivery): self
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
            $delivery->failureReason()?->value,
            $scans,
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
            'vehicle_id' => $this->vehicleId,
            'status' => $this->status,
            'pod_confirmation' => $this->podConfirmation,
            'pod_photo_url' => $this->podPhotoUrl,
            'failure_reason' => $this->failureReason,
            'scans' => array_map(static fn (ScanEventView $scan): array => $scan->toArray(), $this->scans),
        ];
    }
}
