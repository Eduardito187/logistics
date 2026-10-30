<?php

declare(strict_types=1);

namespace Commerce\Payments\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del inicio de pago de una orden (puerta `channels`).
 *
 * El storefront envía el id de la orden, el monto (congelado, client-side) y el método. `payments` no
 * conoce `orders`: la referencia por id es suficiente. La captura la confirma luego el webhook de la
 * pasarela (puerta `integrations`).
 */
final class InitiatePaymentRequest extends FormRequest
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
            'order_id' => ['required', 'uuid'],
            'amount_cents' => ['required', 'integer', 'min:1'],
            'currency' => ['nullable', 'string', 'size:3'],
            'method' => ['required', 'string', 'max:64'],
        ];
    }
}
