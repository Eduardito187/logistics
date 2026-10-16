<?php

declare(strict_types=1);

namespace Logistics\Settlement\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para marcar una liquidación de seller como pagada (requiere referencia del pago).
 */
final class MarkSettlementPaidRequest extends FormRequest
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
            'payment_reference' => ['required', 'string', 'max:255'],
        ];
    }
}
