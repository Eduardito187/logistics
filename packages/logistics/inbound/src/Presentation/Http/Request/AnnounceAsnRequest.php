<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para anunciar un ASN. La autorización por puerta la resuelve el middleware del
 * integrador; aquí solo se valida la forma del cuerpo.
 */
final class AnnounceAsnRequest extends FormRequest
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
            'seller_id' => ['required', 'string', 'uuid'],
            'warehouse_id' => ['required', 'string', 'uuid'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.sku' => ['required', 'string', 'max:128'],
            'lines.*.expected_quantity' => ['required', 'integer', 'min:1'],
        ];
    }
}
