<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validación de la ubicación en estante de productos recibidos en un nodo.
 *
 * Dos formas: un producto (el id va en la ruta `.../units/{unitId}/location`) o un lote
 * (`.../units/location` con `unit_ids: [...]`, misma ubicación para todos). device_id/user_id opcionales
 * (pueden venir del token de Auth).
 */
final class AssignShelfLocationRequest extends FormRequest
{
    /** Tope del lote: una estantería, no un depósito entero por llamada. */
    private const int MAX_BATCH = 200;

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
            'node_id' => ['required', 'uuid'],
            'location' => ['required', 'string', 'min:1', 'max:64'],
            'occurred_at' => ['required', 'date'],
            'unit_ids' => [
                Rule::requiredIf(fn (): bool => $this->route('unitId') === null),
                'array',
                'min:1',
                'max:'.self::MAX_BATCH,
            ],
            'unit_ids.*' => ['required', 'string', 'max:64'],
            'device_id' => ['sometimes', 'string', 'max:128'],
            'user_id' => ['sometimes', 'string', 'max:128'],
        ];
    }
}
