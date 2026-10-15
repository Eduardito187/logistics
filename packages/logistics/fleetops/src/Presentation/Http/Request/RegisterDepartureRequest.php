<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para registrar la salida/despacho de un vehículo de flota propia.
 *
 * La autorización por puerta/credencial la resuelve el middleware del integrador; aquí solo se valida
 * la forma de la entrada. El handler convierte los primitivos a los VO del dominio.
 */
final class RegisterDepartureRequest extends FormRequest
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
            'vehicle_id' => ['required', 'string', 'uuid'],
            'driver_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'helper_ids' => ['sometimes', 'array'],
            'helper_ids.*' => ['string', 'uuid'],
            'destination' => ['sometimes', 'nullable', 'string', 'max:255'],
            'route_ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'load' => ['sometimes', 'array'],
            'load.description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'load.weight_kg' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'load.items' => ['sometimes', 'array'],
            'load.items.*.name' => ['required', 'string', 'max:255'],
            'load.items.*.qty' => ['required', 'integer', 'min:1'],
            'odometer_out_km' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'departed_at' => ['required', 'string', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
