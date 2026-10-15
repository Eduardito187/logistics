<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida los parámetros del cálculo de promesa: la zona (obligatoria), la fecha de inicio del horizonte
 * (opcional, Y-m-d) y cuántos días explorar (opcional, acotado). La autorización por puerta/credencial de
 * canal la resuelve el middleware; aquí solo se valida la forma.
 */
final class CalculateDeliveryPromiseRequest extends FormRequest
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
            'zone_id' => ['required', 'string', 'max:64'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'horizon_days' => ['sometimes', 'integer', 'min:1', 'max:60'],
        ];
    }
}
