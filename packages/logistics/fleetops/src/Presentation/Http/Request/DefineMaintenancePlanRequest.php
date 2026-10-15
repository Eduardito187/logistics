<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para definir un plan de mantenimiento preventivo (por km y/o por días).
 *
 * La autorización por puerta/credencial la resuelve el middleware del integrador; aquí solo se valida
 * la forma de la entrada. El handler convierte los primitivos a los VO del dominio.
 */
final class DefineMaintenancePlanRequest extends FormRequest
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
            'service_kind' => ['required', 'string', 'in:oil_change,tires,brakes,filters,battery,general,inspection'],
            'interval_km' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'interval_days' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
