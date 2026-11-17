<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del alta de un motivo de entrega fallida (admin). El `code` es opcional: si no viene, se
 * deriva de la etiqueta. El acceso por rol lo aplica el middleware de la ruta.
 */
final class CreateFailureReasonRequest extends FormRequest
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
            'label' => ['required', 'string', 'max:160'],
            'code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'active' => ['sometimes', 'boolean'],
            'requires_photo' => ['sometimes', 'boolean'],
            'requires_note' => ['sometimes', 'boolean'],
            'note_label' => ['sometimes', 'nullable', 'string', 'max:160'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}
