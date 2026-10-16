<?php

declare(strict_types=1);

namespace Logistics\Settlement\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para armar el borrador de una liquidación de seller.
 *
 * Los términos (comisión/fee) llegan en el cuerpo porque `settlement` no depende del contexto `sellers`:
 * el integrador los toma de los SettlementTerms del seller y los pasa acá.
 */
final class DraftSellerSettlementRequest extends FormRequest
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
            'period_from' => ['required', 'date_format:Y-m-d'],
            'period_to' => ['required', 'date_format:Y-m-d'],
            'commission_basis_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'fixed_fee_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
        ];
    }
}
