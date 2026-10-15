<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para que un transportista envíe una liquidación por un corredor y período.
 *
 * El corredor llega por sus tres primitivos, el monto como centavos + moneda y el período como texto
 * `YYYY-MM`, igual que el Command SubmitSettlement; el handler los convierte a los VO del dominio.
 */
final class SubmitSettlementRequest extends FormRequest
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
            'corridor_from' => ['required', 'string', 'max:255'],
            'corridor_to' => ['required', 'string', 'max:255'],
            'corridor_type' => ['required', 'string', 'in:cd_to_customer,cd_to_cd,cd_to_store,store_to_store,store_to_cd,store_pickup'],
            'period' => ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'reference' => ['required', 'string', 'max:255'],
            'documents' => ['sometimes', 'array'],
            'documents.*.type' => ['required_with:documents', 'string', 'max:64'],
            'documents.*.reference' => ['required_with:documents', 'string', 'max:255'],
        ];
    }
}
