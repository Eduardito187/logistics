<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de un lote de lecturas GPS (pings) de un vehículo.
 *
 * El `source` NO llega del cuerpo: lo fija la puerta (app vs integración), igual que el Command
 * RecordVehiclePings. Aquí solo se valida la forma del lote: vehículo + lista de lecturas con
 * posición e instante obligatorios y el resto de la telemetría opcional.
 */
final class RecordVehiclePingsRequest extends FormRequest
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
            'vehicle_id' => ['required', 'string', 'max:64'],
            'driver_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'route_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pings' => ['required', 'array', 'min:1'],
            'pings.*.lat' => ['required', 'numeric', 'between:-90,90'],
            'pings.*.lng' => ['required', 'numeric', 'between:-180,180'],
            'pings.*.recorded_at' => ['required', 'date'],
            'pings.*.speed_kph' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'pings.*.heading' => ['sometimes', 'nullable', 'integer', 'between:0,359'],
            'pings.*.accuracy_m' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'pings.*.ignition' => ['sometimes', 'nullable', 'boolean'],
            'pings.*.odometer_m' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'pings.*.device_id' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }
}
