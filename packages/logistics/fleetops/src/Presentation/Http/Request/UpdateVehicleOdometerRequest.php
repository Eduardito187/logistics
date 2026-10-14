<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para fijar el odómetro vigente de un vehículo (solo avanza).
 *
 * La autorización por puerta/credencial la resuelve el middleware del integrador; aquí solo se valida
 * la forma de la entrada. El handler convierte los primitivos a los VO del dominio.
 */
final class UpdateVehicleOdometerRequest extends FormRequest
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
            'km' => ['required', 'integer', 'min:0'],
        ];
    }
}
