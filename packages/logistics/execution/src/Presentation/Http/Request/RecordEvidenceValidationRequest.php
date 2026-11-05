<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Execution\Domain\ValueObject\EvidenceValidationStatus;

/**
 * Validación del write-back de validación de evidencia (puerta integrations: lo llama el validador
 * asíncrono / sidecar on-premise). O bien `status` explícito (ok|review|mismatch), o bien
 * `ocr_document_number` para que el backend cruce el número contra el documento del cliente.
 */
final class RecordEvidenceValidationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $statuses = array_map(static fn (EvidenceValidationStatus $s): string => $s->value, EvidenceValidationStatus::cases());

        return [
            'status' => ['sometimes', 'nullable', 'string', 'in:'.implode(',', $statuses)],
            'ocr_document_number' => ['sometimes', 'nullable', 'string', 'max:64'],
            'detail' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'validated_at' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
