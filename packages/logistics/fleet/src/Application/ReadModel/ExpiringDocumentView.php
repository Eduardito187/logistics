<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\ReadModel;

/**
 * Un documento por vencer (o ya vencido), con el dueño al que pertenece (vehículo o chofer).
 *
 * Alimenta el tablero de documentos por vencer: qué entidad, qué documento y para cuándo.
 */
final readonly class ExpiringDocumentView
{
    /**
     * @param 'vehicle'|'driver' $ownerType
     */
    private function __construct(
        public string $ownerType,
        public string $ownerId,
        public string $ownerLabel,
        public string $documentType,
        public string $expiresOn,
        public bool $expired,
    ) {}

    public static function forVehicle(string $vehicleId, string $plate, string $documentType, string $expiresOn, bool $expired): self
    {
        return new self('vehicle', $vehicleId, $plate, $documentType, $expiresOn, $expired);
    }

    public static function forDriver(string $driverId, string $name, string $documentType, string $expiresOn, bool $expired): self
    {
        return new self('driver', $driverId, $name, $documentType, $expiresOn, $expired);
    }

    /**
     * @return array{owner_type: string, owner_id: string, owner_label: string, document_type: string, expires_on: string, expired: bool}
     */
    public function toArray(): array
    {
        return [
            'owner_type' => $this->ownerType,
            'owner_id' => $this->ownerId,
            'owner_label' => $this->ownerLabel,
            'document_type' => $this->documentType,
            'expires_on' => $this->expiresOn,
            'expired' => $this->expired,
        ];
    }
}
