<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\ReadModel;

use Logistics\Execution\Domain\Model\FailureReason;

/**
 * Vista primitiva de un motivo de entrega fallida del catálogo, para la API (chofer y admin). Nunca expone
 * el UUID interno: el contrato con la app usa el `code` (slug) como identidad.
 */
final readonly class FailureReasonView
{
    public function __construct(
        public string $code,
        public string $label,
        public bool $active,
        public bool $requiresPhoto,
        public bool $requiresNote,
        public ?string $noteLabel,
        public int $sortOrder,
    ) {}

    public static function fromAggregate(FailureReason $reason): self
    {
        return new self(
            $reason->code()->value,
            $reason->label(),
            $reason->isActive(),
            $reason->requiresPhoto(),
            $reason->requiresNote(),
            $reason->noteLabel(),
            $reason->sortOrder(),
        );
    }

    /**
     * @return array{code: string, label: string, active: bool, requires_photo: bool, requires_note: bool, note_label: string|null, sort_order: int}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'label' => $this->label,
            'active' => $this->active,
            'requires_photo' => $this->requiresPhoto,
            'requires_note' => $this->requiresNote,
            'note_label' => $this->noteLabel,
            'sort_order' => $this->sortOrder,
        ];
    }
}
