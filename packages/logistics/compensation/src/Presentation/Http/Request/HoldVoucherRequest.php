<?php

declare(strict_types=1);

namespace Logistics\Compensation\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de la reserva (hold) de un vale para una venta del POS: canal opcional (default POS) y ttl
 * en minutos opcional (default de la política). El código llega por la ruta.
 */
final class HoldVoucherRequest extends FormRequest
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
            'channel' => ['sometimes', 'nullable', 'string', 'max:64'],
            'store_code' => ['sometimes', 'nullable', 'string', 'max:64'],
            'ttl_minutes' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:1440'],
        ];
    }
}
