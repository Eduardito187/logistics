<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del SOBRE de un lote de lecturas GPS (pings) de un vehículo.
 *
 * El `source` NO llega del cuerpo: lo fija la puerta (app vs integración), igual que el Command
 * RecordVehiclePings. Aquí solo se valida el sobre: vehículo + lista de 1..`ingest.max_batch` lecturas.
 *
 * Cada lectura se valida POR SEPARADO en el controller (`itemRules()`), para responder éxito parcial (207):
 * una lectura mal formada se rechaza sola y no tira el lote entero.
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
        $max = max(1, (int) config('logistics-telemetry.ingest.max_batch', 500));

        return [
            'vehicle_id' => ['required', 'string', 'max:64'],
            'driver_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'route_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'pings' => ['required', 'array', 'min:1', 'max:'.$max],
        ];
    }

    /**
     * Reglas de UNA lectura del lote (posición e instante obligatorios, el resto opcional).
     *
     * @return array<string, list<string>>
     */
    public static function itemRules(): array
    {
        return [
            'lat' => ['required', 'numeric', 'between:-90,90'],
            'lng' => ['required', 'numeric', 'between:-180,180'],
            'recorded_at' => ['required', 'date'],
            'speed_kph' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'heading' => ['sometimes', 'nullable', 'integer', 'between:0,359'],
            'accuracy_m' => ['sometimes', 'nullable', 'numeric', 'min:0'],
            'ignition' => ['sometimes', 'nullable', 'boolean'],
            'odometer_m' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'device_id' => ['sometimes', 'nullable', 'string', 'max:128'],
            'fix_id' => ['sometimes', 'nullable', 'string', 'max:128'],
        ];
    }
}
