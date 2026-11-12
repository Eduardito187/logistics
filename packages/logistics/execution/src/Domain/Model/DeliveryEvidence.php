<?php

declare(strict_types=1);

namespace Logistics\Execution\Domain\Model;

use DateTimeImmutable;
use Logistics\Execution\Domain\Event\DeliveryEvidenceCaptured;
use Logistics\Execution\Domain\Event\DeliveryEvidenceValidated;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\CustomerIdentitySnapshot;
use Logistics\Execution\Domain\ValueObject\DeliveryEvidenceId;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\EvidenceKind;
use Logistics\Execution\Domain\ValueObject\EvidenceValidationStatus;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Evidencia de entrega LIGADA AL CLIENTE (anti-fraude): firma, foto de documento, prueba de entrega o
 * daño. Es un hecho con AUDITORÍA INMUTABLE — quién la capturó, cuándo, con qué GPS, y el HASH SHA-256 del
 * archivo — de modo que una evidencia no se pueda intercambiar por otra (si el archivo cambia, el hash no
 * cuadra). Queda ligada a un snapshot de la identidad del cliente para poder VALIDARLA contra él.
 *
 * La captura es inmutable; lo único que evoluciona es el ESTADO DE VALIDACIÓN (pending → ok|review|mismatch),
 * que lo escribe un validador asíncrono (worker/sidecar on-premise). La validación es SEÑAL, no bloqueo:
 * jamás frena la entrega por sí sola (ver ADR de evidencia anti-fraude).
 *
 * Referencia al envío por id string: no se acopla a shipments.
 */
final class DeliveryEvidence extends AggregateRoot
{
    private const string SHA256_PATTERN = '/^[0-9a-f]{64}$/';

    private function __construct(
        private readonly DeliveryEvidenceId $id,
        private readonly string $shipmentId,
        private readonly EvidenceKind $kind,
        private readonly CustomerIdentitySnapshot $customer,
        private readonly string $storageDisk,
        private readonly string $storagePath,
        private readonly string $sha256,
        private readonly DeviceContext $capturedBy,
        private readonly ?GeoPoint $location,
        private readonly DateTimeImmutable $capturedAt,
        private readonly ?string $idempotencyKey,
        private EvidenceValidationStatus $validationStatus,
        private ?string $validationDetail,
        private ?DateTimeImmutable $validatedAt,
    ) {}

    /**
     * Captura una evidencia nueva (estado de validación PENDING) y graba el evento que dispara la
     * validación asíncrona por el outbox.
     */
    public static function capture(
        DeliveryEvidenceId $id,
        string $shipmentId,
        EvidenceKind $kind,
        CustomerIdentitySnapshot $customer,
        string $storageDisk,
        string $storagePath,
        string $sha256,
        DeviceContext $capturedBy,
        ?GeoPoint $location,
        DateTimeImmutable $capturedAt,
        ?string $idempotencyKey = null,
    ): self {
        $shipment = trim($shipmentId);
        if ($shipment === '') {
            throw ExecutionException::because('la evidencia de entrega requiere el id del envío');
        }

        $disk = trim($storageDisk);
        $path = trim($storagePath);
        if ($disk === '' || $path === '') {
            throw ExecutionException::because('la evidencia de entrega requiere la referencia de almacenamiento');
        }

        $hash = strtolower(trim($sha256));
        if (preg_match(self::SHA256_PATTERN, $hash) !== 1) {
            throw ExecutionException::because('el hash de integridad de la evidencia debe ser un SHA-256 (64 hex)');
        }

        $evidence = new self(
            $id,
            $shipment,
            $kind,
            $customer,
            $disk,
            $path,
            $hash,
            $capturedBy,
            $location,
            $capturedAt,
            self::clean($idempotencyKey),
            EvidenceValidationStatus::Pending,
            null,
            null,
        );

        $evidence->recordEvent(new DeliveryEvidenceCaptured(
            $id->toString(),
            $shipment,
            $kind->value,
            $customer->customerId,
            $customer->documentId,
            $hash,
            $capturedAt,
        ));

        return $evidence;
    }

    /**
     * Reconstituye el agregado desde la persistencia (SIN grabar eventos): lo usa el repositorio al cargar
     * una evidencia para aplicarle el resultado de validación.
     */
    public static function reconstitute(
        DeliveryEvidenceId $id,
        string $shipmentId,
        EvidenceKind $kind,
        CustomerIdentitySnapshot $customer,
        string $storageDisk,
        string $storagePath,
        string $sha256,
        DeviceContext $capturedBy,
        ?GeoPoint $location,
        DateTimeImmutable $capturedAt,
        ?string $idempotencyKey,
        EvidenceValidationStatus $validationStatus,
        ?string $validationDetail,
        ?DateTimeImmutable $validatedAt,
    ): self {
        return new self(
            $id,
            $shipmentId,
            $kind,
            $customer,
            $storageDisk,
            $storagePath,
            $sha256,
            $capturedBy,
            $location,
            $capturedAt,
            $idempotencyKey,
            $validationStatus,
            $validationDetail,
            $validatedAt,
        );
    }

    /**
     * Aplica el resultado de la validación (lo escribe el validador asíncrono). Idempotente en el sentido
     * de que simplemente fija el último estado; no muta nada de la captura (auditoría inmutable).
     *
     * Graba DeliveryEvidenceValidated solo cuando el resultado CAMBIA a un estado decidido (ok, review o
     * mismatch): reenviar el mismo resultado (reintento del validador) no duplica el hecho, y un "pending"
     * no es una validación. `validatedBy` identifica al validador (integración, usuario de revisión).
     */
    public function applyValidation(
        EvidenceValidationStatus $status,
        ?string $detail,
        DateTimeImmutable $validatedAt,
        ?string $validatedBy = null,
    ): void {
        $previous = $this->validationStatus;

        $this->validationStatus = $status;
        $this->validationDetail = self::clean($detail);
        $this->validatedAt = $validatedAt;

        if ($status === $previous || $status === EvidenceValidationStatus::Pending) {
            return;
        }

        $this->recordEvent(new DeliveryEvidenceValidated(
            $this->id->toString(),
            $this->shipmentId,
            $this->kind->value,
            $status->value,
            $previous->value,
            self::clean($validatedBy),
            $validatedAt,
        ));
    }

    public function id(): DeliveryEvidenceId
    {
        return $this->id;
    }

    public function shipmentId(): string
    {
        return $this->shipmentId;
    }

    public function kind(): EvidenceKind
    {
        return $this->kind;
    }

    public function customer(): CustomerIdentitySnapshot
    {
        return $this->customer;
    }

    public function storageDisk(): string
    {
        return $this->storageDisk;
    }

    public function storagePath(): string
    {
        return $this->storagePath;
    }

    public function sha256(): string
    {
        return $this->sha256;
    }

    public function capturedBy(): DeviceContext
    {
        return $this->capturedBy;
    }

    public function location(): ?GeoPoint
    {
        return $this->location;
    }

    public function capturedAt(): DateTimeImmutable
    {
        return $this->capturedAt;
    }

    public function idempotencyKey(): ?string
    {
        return $this->idempotencyKey;
    }

    public function validationStatus(): EvidenceValidationStatus
    {
        return $this->validationStatus;
    }

    public function validationDetail(): ?string
    {
        return $this->validationDetail;
    }

    public function validatedAt(): ?DateTimeImmutable
    {
        return $this->validatedAt;
    }

    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
