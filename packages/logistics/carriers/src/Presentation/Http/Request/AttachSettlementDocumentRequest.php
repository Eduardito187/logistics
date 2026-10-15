<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para adjuntar un documento (factura, comprobante) a una liquidación.
 */
final class AttachSettlementDocumentRequest extends FormRequest
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
            'type' => ['required', 'string', 'max:64'],
            'reference' => ['required', 'string', 'max:255'],
        ];
    }
}
