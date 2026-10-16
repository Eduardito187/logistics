<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Filament\Support;

use Logistics\Inbound\Domain\ValueObject\QcOutcome;

/**
 * Opciones valor→etiqueta (en inglés) del resultado de QC, para los selects del admin.
 */
final class QcOutcomeOptions
{
    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        $options = [];
        foreach (QcOutcome::cases() as $case) {
            $options[$case->value] = __($case->label());
        }

        return $options;
    }
}
