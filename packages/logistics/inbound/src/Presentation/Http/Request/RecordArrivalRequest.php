<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para registrar la llegada del camión del seller.
 */
final class RecordArrivalRequest extends FormRequest
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
            'asn_id' => ['required', 'string', 'uuid'],
            'carrier_reference' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }
}
