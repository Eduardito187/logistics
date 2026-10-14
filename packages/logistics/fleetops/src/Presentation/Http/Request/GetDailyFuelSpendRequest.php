<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del parámetro de consulta para el gasto de combustible de un día ('Y-m-d').
 *
 * La autorización por puerta/credencial la resuelve el middleware del integrador; aquí solo se valida
 * la forma de la entrada. El handler convierte los primitivos a los VO del dominio.
 */
final class GetDailyFuelSpendRequest extends FormRequest
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
            'date' => ['required', 'string', 'date_format:Y-m-d'],
        ];
    }
}
