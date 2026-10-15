<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Logistics\Execution\Domain\ValueObject\FailureReason;

/**
 * Validación de un intento de entrega fallido. El motivo debe ser uno de FailureReason.
 */
final class FailedDeliveryRequest extends ScanRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $reasons = array_map(static fn (FailureReason $reason): string => $reason->value, FailureReason::cases());

        return array_merge($this->baseRules(), [
            'reason' => ['required', 'string', 'in:'.implode(',', $reasons)],
        ]);
    }
}
