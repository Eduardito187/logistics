<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\FleetOps\Domain\ValueObject\OdometerSource;

/**
 * Validación de entrada para registrar una lectura de odómetro de un vehículo.
 *
 * La autorización por puerta/credencial la resuelve el middleware del integrador; aquí solo se valida
 * la forma de la entrada. El handler convierte los primitivos a los VO del dominio.
 */
final class RecordOdometerRequest extends FormRequest
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
            'km' => ['required', 'integer', 'min:0'],
            // Fuentes válidas derivadas del enum (incluye `arrival`): nunca se desincroniza con el dominio.
            'source' => ['required', 'string', Rule::in(array_map(static fn (OdometerSource $s): string => $s->value, OdometerSource::cases()))],
            'read_at' => ['required', 'string', 'date'],
            'note' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Ruta del chofer a la que pertenece la lectura (salida/llegada). Opcional.
            'route_id' => ['sometimes', 'nullable', 'string', 'uuid'],
        ];
    }
}
