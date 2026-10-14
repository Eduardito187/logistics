<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para fijar el precio por litro de un combustible, vigente desde una fecha.
 *
 * La autorización por puerta/credencial la resuelve el middleware del integrador; aquí solo se valida
 * la forma de la entrada. El handler convierte los primitivos a los VO del dominio.
 */
final class SetFuelPriceRequest extends FormRequest
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
            'fuel_type' => ['required', 'string', 'in:gasoline_special,gasoline_premium,diesel,gnv'],
            'price_cents' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'effective_from' => ['required', 'string', 'date_format:Y-m-d'],
            'source' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}
