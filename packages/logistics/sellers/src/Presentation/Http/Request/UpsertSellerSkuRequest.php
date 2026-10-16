<?php

declare(strict_types=1);

namespace Logistics\Sellers\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para dar de alta o actualizar un SKU del catálogo de un seller.
 */
final class UpsertSellerSkuRequest extends FormRequest
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
            'sku' => ['required', 'string', 'max:128'],
            'sap_material' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }
}
