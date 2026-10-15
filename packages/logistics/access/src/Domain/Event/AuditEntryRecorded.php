<?php

declare(strict_types=1);

namespace Logistics\Access\Domain\Event;

use Logistics\Shared\Domain\Event\BaseDomainEvent;

/**
 * Se registró una entrada en la bitácora de auditoría de accesos: una acción sensible (alta de usuario,
 * suspensión, cambio de roles o permisos, emisión/revocación de token, etc.) con su autor y contexto.
 *
 * El hecho ya quedó persistido de forma inmutable en `access_audit_log`; este evento lo publica además
 * al outbox para que SIEM, alertas o análisis externos puedan consumirlo sin tocar la base (principio 3).
 */
final readonly class AuditEntryRecorded extends BaseDomainEvent
{
    /**
     * @param array<string, mixed> $context
     */
    public function __construct(
        string $aggregateId,
        public ?string $actorId,
        public string $actorLabel,
        public string $action,
        public string $subjectType,
        public ?string $subjectId,
        public array $context,
        public ?string $ipAddress,
    ) {
        parent::__construct($aggregateId);
    }

    public function eventName(): string
    {
        return 'access.audit_entry_recorded';
    }

    /**
     * @return array{actor_id: string|null, actor_label: string, action: string, subject_type: string, subject_id: string|null, context: array<string, mixed>, ip_address: string|null}
     */
    public function payload(): array
    {
        return [
            'actor_id' => $this->actorId,
            'actor_label' => $this->actorLabel,
            'action' => $this->action,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'context' => $this->context,
            'ip_address' => $this->ipAddress,
        ];
    }
}
