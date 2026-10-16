<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para registrar la recepción física de un ASN con QC por línea.
 */
final class RecordGoodsReceiptRequest extends FormRequest
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
            'seller_sku_ref' => ['sometimes', 'nullable', 'string', 'max:128'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['required', 'string', 'max:128'],
            'lines.*.accepted' => ['required', 'integer', 'min:0'],
            'lines.*.rejected' => ['required', 'integer', 'min:0'],
            'lines.*.damaged' => ['required', 'integer', 'min:0'],
            'lines.*.qc_outcome' => ['required', 'string', 'in:accepted,rejected,partial_damage'],
        ];
    }
}
