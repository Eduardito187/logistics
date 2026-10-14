<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para agendar una orden de mantenimiento de un vehículo.
 *
 * La autorización por puerta/credencial la resuelve el middleware del integrador; aquí solo se valida
 * la forma de la entrada. El handler convierte los primitivos a los VO del dominio.
 */
final class ScheduleMaintenanceRequest extends FormRequest
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
            'type' => ['required', 'string', 'in:preventive,corrective,inspection'],
            'odometer_km' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'workshop' => ['sometimes', 'nullable', 'string', 'max:255'],
            'scheduled_at' => ['required', 'string', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
