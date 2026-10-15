<?php

declare(strict_types=1);

namespace Logistics\Access\Application\ReadModel;

use DateTimeInterface;
use Logistics\Access\Domain\Model\AuditEntry;

/**
 * Proyección de lectura de una entrada de auditoría para listados y API. Solo primitivos: no expone
 * objetos de dominio.
 */
final readonly class AuditEntryView
{
    /**
     * @param array<string, mixed> $context
     */
    private function __construct(
        public string $id,
        public ?string $actorId,
        public string $actorLabel,
        public string $action,
        public string $subjectType,
        public ?string $subjectId,
        public array $context,
        public ?string $ipAddress,
        public string $occurredAt,
    ) {}

    public static function fromDomain(AuditEntry $entry): self
    {
        return new self(
            $entry->id()->toString(),
            $entry->actorId(),
            $entry->actorLabel(),
            $entry->action()->value,
            $entry->subjectType(),
            $entry->subjectId(),
            $entry->context(),
            $entry->ipAddress(),
            $entry->occurredAt()->format(DateTimeInterface::ATOM),
        );
    }

    /**
     * @return array{id: string, actor_id: string|null, actor_label: string, action: string, subject_type: string, subject_id: string|null, context: array<string, mixed>, ip_address: string|null, occurred_at: string}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'actor_id' => $this->actorId,
            'actor_label' => $this->actorLabel,
            'action' => $this->action,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'context' => $this->context,
            'ip_address' => $this->ipAddress,
            'occurred_at' => $this->occurredAt,
        ];
    }
}
