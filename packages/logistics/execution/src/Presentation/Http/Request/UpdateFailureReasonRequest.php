<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la edición de un motivo de entrega fallida (admin). El `code` llega en la ruta (es
 * inmutable); aquí solo se validan los campos editables. Edición parcial: todo es opcional.
 */
final class UpdateFailureReasonRequest extends FormRequest
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
        return [
            'label' => ['sometimes', 'string', 'max:160'],
            'active' => ['sometimes', 'boolean'],
            'requires_photo' => ['sometimes', 'boolean'],
            'requires_note' => ['sometimes', 'boolean'],
            'note_label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
