<?php

declare(strict_types=1);

namespace Logistics\Sellers\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para pactar/actualizar los términos de liquidación de un seller.
 */
final class SetSettlementTermsRequest extends FormRequest
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
            'commission_basis_points' => ['required', 'integer', 'min:0', 'max:10000'],
            'fixed_fee_cents' => ['required', 'integer', 'min:0'],
            'currency' => ['sometimes', 'string', 'size:3'],
            'payment_cycle' => ['required', 'string', 'in:weekly,biweekly,monthly'],
        ];
    }
}
