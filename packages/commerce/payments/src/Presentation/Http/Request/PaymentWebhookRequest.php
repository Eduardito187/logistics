<?php

declare(strict_types=1);

namespace Commerce\Payments\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del cuerpo del webhook de confirmación de la pasarela.
 *
 * La pasarela confirma el cobro identificando el pago por su id o por la orden (al menos uno), más la
 * referencia del proveedor. La autenticación del webhook (firma/HMAC) la resuelve el middleware del
 * integrador; aquí solo se valida la forma del cuerpo.
 */
final class PaymentWebhookRequest extends FormRequest
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
            'payment_id' => ['required_without:order_id', 'nullable', 'uuid'],
            'order_id' => ['required_without:payment_id', 'nullable', 'uuid'],
            'gateway_ref' => ['required', 'string', 'max:255'],
        ];
    }
}
