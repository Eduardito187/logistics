<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para etiquetar las unidades aceptadas de la recepción de un ASN.
 */
final class LabelReceivedUnitsRequest extends FormRequest
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
        ];
    }
}
