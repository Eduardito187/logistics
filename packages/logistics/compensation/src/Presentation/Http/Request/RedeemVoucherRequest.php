<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del canje de un vale desde el POS: referencia de la venta obligatoria (clave de idempotencia)
 * y monto usado en centavos obligatorio. Canal y email del cliente opcionales. El código llega por la ruta.
 */
final class RedeemVoucherRequest extends FormRequest
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
            'sale_ref' => ['required', 'string', 'max:191'],
            'amount_used_cents' => ['required', 'integer', 'min:1'],
            'channel' => ['sometimes', 'nullable', 'string', 'max:64'],
            'store_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:191'],
        ];
    }
}
