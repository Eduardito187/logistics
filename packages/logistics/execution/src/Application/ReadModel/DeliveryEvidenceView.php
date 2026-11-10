<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

/**
 * Vista plana de una evidencia de entrega con su estado de validación anti-fraude, para la app.
 *
 * Lleva la referencia de almacenamiento y el hash (integridad), el snapshot del cliente contra el que se
 * valida, y el estado (pending|ok|review|mismatch) con su detalle. La app muestra el estado; el hash
 * permite verificar que el archivo no se intercambió.
 */
final readonly class DeliveryEvidenceView
{
    /**
     * @param array{id: ?string, document_id: ?string, name: ?string, phone: ?string} $customer
     * @param array{lat: ?float, lng: ?float}                                         $location
     */
    public function __construct(
        public string $id,
        public string $shipmentId,
        public string $kind,
        public string $validationStatus,
        public ?string $validationDetail,
        public string $sha256,
        public string $storageDisk,
        public string $storagePath,
        public array $customer,
        public array $location,
        public ?string $capturedAt,
        public ?string $validatedAt,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'shipment_id' => $this->shipmentId,
            'kind' => $this->kind,
            'validation_status' => $this->validationStatus,
            'validation_detail' => $this->validationDetail,
            'sha256' => $this->sha256,
            'storage' => ['disk' => $this->storageDisk, 'path' => $this->storagePath],
            'customer' => $this->customer,
            'location' => $this->location,
            'captured_at' => $this->capturedAt,
            'validated_at' => $this->validatedAt,
        ];
    }
}
