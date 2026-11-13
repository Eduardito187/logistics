<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la consulta de validez de un vale desde el POS: código obligatorio, tienda y email del
 * cliente opcionales. El formato/dígito de control del código lo valida el dominio (404 genérico).
 */
final class ValidateVoucherRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:32'],
            'store_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'customer_email' => ['sometimes', 'nullable', 'email', 'max:191'],
        ];
    }
}
