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
     * `ownerType` es uno de 'vehicle'|'driver'|'helper'; se guarda como texto para la proyección.
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

    public static function forHelper(string $helperId, string $name, string $documentType, string $expiresOn, bool $expired): self
    {
        return new self('helper', $helperId, $name, $documentType, $expiresOn, $expired);
    }

    /**
     * Documento de flota almacenado como entidad propia (con archivo S3). El dueño ya viene resuelto.
     * `ownerType` es uno de 'vehicle'|'driver'|'helper'.
     */
    public static function forDocument(string $ownerType, string $ownerId, string $ownerLabel, string $documentType, string $expiresOn, bool $expired): self
    {
        return new self($ownerType, $ownerId, $ownerLabel, $documentType, $expiresOn, $expired);
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
