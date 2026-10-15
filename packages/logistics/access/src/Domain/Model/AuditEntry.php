<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Model;

use DateTimeImmutable;
use Logistics\Access\Domain\Event\AuditEntryRecorded;
use Logistics\Access\Domain\Exception\AccessException;
use Logistics\Access\Domain\ValueObject\AuditAction;
use Logistics\Access\Domain\ValueObject\AuditEntryId;
use Logistics\Shared\Domain\Aggregate\AggregateRoot;

/**
 * Entrada inmutable de la bitácora de auditoría de accesos.
 *
 * Deja constancia de QUIÉN (actor), QUÉ (acción), SOBRE QUÉ (sujeto), CUÁNDO y DESDE DÓNDE (IP) ocurrió
 * una acción sensible del módulo de acceso. Es un registro append-only: una vez grabado no se edita ni
 * se borra (por eso no hay mutadores ni página de edición en el admin). El agregado solo se crea con
 * {@see self::record()} —que graba el evento de dominio— o se reconstituye desde persistencia.
 */
final class AuditEntry extends AggregateRoot
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        private readonly AuditEntryId $id,
        private readonly ?string $actorId,
        private readonly string $actorLabel,
        private readonly AuditAction $action,
        private readonly string $subjectType,
        private readonly ?string $subjectId,
        private readonly array $context,
        private readonly ?string $ipAddress,
        private readonly DateTimeImmutable $occurredAt,
    ) {}

    /**
     * Graba una entrada nueva. El autor puede ser nulo (acción del propio sistema); la etiqueta del autor
     * nunca queda vacía (se usa "System" si no hay nombre). El contexto son primitivos serializables a JSON.
     *
     * @param array<string, mixed> $context
     */
    public static function record(
        AuditEntryId $id,
        ?string $actorId,
        string $actorLabel,
        AuditAction $action,
        string $subjectType,
        ?string $subjectId,
        array $context,
        ?string $ipAddress,
        DateTimeImmutable $occurredAt,
    ): self {
        $actorLabel = trim($actorLabel);
        if ($actorLabel === '') {
            $actorLabel = 'System';
        }

        $subjectType = trim($subjectType);
        if ($subjectType === '') {
            throw AccessException::because('El tipo de sujeto de la auditoría no puede estar vacío.');
        }

        $entry = new self(
            $id,
            $actorId,
            $actorLabel,
            $action,
            $subjectType,
            $subjectId,
            $context,
            $ipAddress,
            $occurredAt,
        );

        $entry->recordEvent(new AuditEntryRecorded(
            $id->toString(),
            $actorId,
            $actorLabel,
            $action->value,
            $subjectType,
            $subjectId,
            $context,
            $ipAddress,
        ));

        return $entry;
    }

    /**
     * Reconstituye una entrada desde persistencia, sin grabar eventos (ya ocurrieron).
     *
     * @param array<string, mixed> $context
     */
    public static function reconstitute(
        AuditEntryId $id,
        ?string $actorId,
        string $actorLabel,
        AuditAction $action,
        string $subjectType,
        ?string $subjectId,
        array $context,
        ?string $ipAddress,
        DateTimeImmutable $occurredAt,
    ): self {
        return new self(
            $id,
            $actorId,
            $actorLabel,
            $action,
            $subjectType,
            $subjectId,
            $context,
            $ipAddress,
            $occurredAt,
        );
    }

    public function id(): AuditEntryId
    {
        return $this->id;
    }

    public function actorId(): ?string
    {
        return $this->actorId;
    }

    public function actorLabel(): string
    {
        return $this->actorLabel;
    }

    public function action(): AuditAction
    {
        return $this->action;
    }

    public function subjectType(): string
    {
        return $this->subjectType;
    }

    public function subjectId(): ?string
    {
        return $this->subjectId;
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return $this->context;
    }

    public function ipAddress(): ?string
    {
        return $this->ipAddress;
    }

    public function occurredAt(): DateTimeImmutable
    {
        return $this->occurredAt;
    }
}
