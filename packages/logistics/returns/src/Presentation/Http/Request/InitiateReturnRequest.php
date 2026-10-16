<?php

declare(strict_types=1);

namespace Logistics\Returns\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para iniciar una devolución. La autorización por puerta la resuelve el middleware
 * del integrador; aquí solo se valida la forma del cuerpo.
 */
final class InitiateReturnRequest extends FormRequest
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
            'original_reference' => ['required', 'string', 'max:128'],
            'warehouse_id' => ['required', 'string', 'uuid'],
            'kind' => ['required', 'string', 'in:customer_return,return_to_vendor'],
            'owner_party_id' => ['nullable', 'string', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['required', 'string', 'max:128'],
            'lines.*.quantity' => ['required', 'integer', 'min:1'],
            'lines.*.reason' => ['required', 'string', 'max:255'],
        ];
    }
}
