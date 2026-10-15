<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para registrar una carga de combustible de la flota propia.
 *
 * La autorización por puerta/credencial la resuelve el middleware del integrador; aquí solo se valida
 * la forma de la entrada. El handler convierte los primitivos a los VO del dominio.
 */
final class RecordRefuelRequest extends FormRequest
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
            'fuel_type' => ['required', 'string', 'in:gasoline_special,gasoline_premium,diesel,gnv'],
            'liters' => ['required', 'numeric', 'gt:0'],
            'price_per_liter_cents' => ['required', 'integer', 'min:1'],
            'currency' => ['required', 'string', 'size:3'],
            'odometer_km' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'station' => ['sometimes', 'nullable', 'string', 'max:255'],
            'full_tank' => ['required', 'boolean'],
            'refueled_at' => ['required', 'string', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
