<?php

declare(strict_types=1);

namespace Logistics\Fleet\Domain\Model;

use DateTimeImmutable;
use Logistics\Fleet\Domain\Event\DocumentRegistered;
use Logistics\Fleet\Domain\ValueObject\DocumentExpiry;
use Logistics\Fleet\Domain\ValueObject\DocumentOwnerType;
use Logistics\Fleet\Domain\ValueObject\FleetDocumentId;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Documento de flota con vencimiento y, opcionalmente, su archivo escaneado en almacenamiento S3.
 *
 * Complementa a los documentos embebidos en vehículos y choferes: este agregado existe cuando hace
 * falta guardar el archivo (SOAT, inspección técnica, póliza) y rastrear emisión/vencimiento como una
 * entidad administrable. El dueño (vehículo, chofer o ayudante) se referencia por tipo + id en texto,
 * para no acoplar los documentos a la identidad concreta de cada entidad.
 */
final class FleetDocument extends AggregateRoot
{
    private function __construct(
        private readonly FleetDocumentId $id,
        private readonly DocumentOwnerType $ownerType,
        private readonly string $ownerId,
        private readonly DocumentExpiry $document,
        private readonly ?DateTimeImmutable $issuedOn,
        private readonly ?string $filePath,
        private readonly ?string $note,
    ) {}

    /**
     * Registra un documento de flota nuevo.
     */
    public static function register(
        FleetDocumentId $id,
        DocumentOwnerType $ownerType,
        string $ownerId,
        DocumentExpiry $document,
        ?DateTimeImmutable $issuedOn = null,
        ?string $filePath = null,
        ?string $note = null,
    ): self {
        $doc = new self($id, $ownerType, $ownerId, $document, $issuedOn, $filePath, $note);
        $doc->recordEvent(new DocumentRegistered(
            $id->toString(),
            $ownerType->value,
            $ownerId,
            $document->type,
            $document->expiresOn->format('Y-m-d'),
            $issuedOn?->format('Y-m-d'),
            $filePath,
        ));

        return $doc;
    }

    /**
     * Reconstituye un documento desde persistencia, sin grabar eventos.
     */
    public static function reconstitute(
        FleetDocumentId $id,
        DocumentOwnerType $ownerType,
        string $ownerId,
        DocumentExpiry $document,
        ?DateTimeImmutable $issuedOn,
        ?string $filePath,
        ?string $note,
    ): self {
        return new self($id, $ownerType, $ownerId, $document, $issuedOn, $filePath, $note);
    }

    public function expiresWithin(int $days, DateTimeImmutable $ref): bool
    {
        return $this->document->expiresWithin($days, $ref);
    }

    public function isExpiredAt(DateTimeImmutable $ref): bool
    {
        return $this->document->isExpiredAt($ref);
    }

    public function id(): FleetDocumentId
    {
        return $this->id;
    }

    public function ownerType(): DocumentOwnerType
    {
        return $this->ownerType;
    }

    public function ownerId(): string
    {
        return $this->ownerId;
    }

    public function document(): DocumentExpiry
    {
        return $this->document;
    }

    public function issuedOn(): ?DateTimeImmutable
    {
        return $this->issuedOn;
    }

    public function filePath(): ?string
    {
        return $this->filePath;
    }

    public function note(): ?string
    {
        return $this->note;
    }
}
