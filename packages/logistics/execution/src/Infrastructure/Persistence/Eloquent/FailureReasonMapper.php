<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Persistence\Eloquent;

use Logistics\Execution\Domain\Model\FailureReason;
use Logistics\Execution\Domain\ValueObject\FailureReasonCode;
use Logistics\Execution\Domain\ValueObject\FailureReasonId;

/**
 * Traduce entre el agregado FailureReason y su fila Eloquent. Toda la conversión a/desde primitivos vive
 * acá: ni el dominio ni el repositorio conocen la forma de la tabla.
 */
final class FailureReasonMapper
{
    /**
     * @return array<string, mixed>
     */
    public function toRow(FailureReason $reason): array
    {
        return [
            'id' => $reason->id()->toString(),
            'code' => $reason->code()->value,
            'label' => $reason->label(),
            'active' => $reason->isActive(),
            'requires_photo' => $reason->requiresPhoto(),
            'requires_note' => $reason->requiresNote(),
            'note_label' => $reason->noteLabel(),
            'sort_order' => $reason->sortOrder(),
        ];
    }

    public function toDomain(FailureReasonRecord $record): FailureReason
    {
        return FailureReason::reconstitute(
            FailureReasonId::fromString($record->id),
            FailureReasonCode::fromString($record->code),
            $record->label,
            (bool) $record->active,
            (bool) $record->requires_photo,
            (bool) $record->requires_note,
            $record->note_label,
            (int) $record->sort_order,
        );
    }
}
