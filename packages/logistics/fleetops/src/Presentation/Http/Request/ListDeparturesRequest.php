<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de los filtros de consulta para listar salidas.
 *
 * La autorización por puerta/credencial la resuelve el middleware del integrador; aquí solo se valida
 * la forma de la entrada. El handler convierte los primitivos a los VO del dominio.
 */
final class ListDeparturesRequest extends FormRequest
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
            'vehicle_id' => ['sometimes', 'nullable', 'string', 'uuid'],
            'status' => ['sometimes', 'nullable', 'string', 'in:out,returned'],
            'from' => ['sometimes', 'nullable', 'string', 'date'],
            'to' => ['sometimes', 'nullable', 'string', 'date'],
        ];
    }
}
