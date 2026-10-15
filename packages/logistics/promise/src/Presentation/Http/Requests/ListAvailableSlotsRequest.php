<?php

declare(strict_types=1);

namespace Logistics\Promise\Presentation\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida los parámetros de consulta para listar franjas disponibles de una zona en una fecha.
 *
 * La autorización por puerta/credencial de canal la resuelve el middleware; aquí solo se valida la forma.
 */
final class ListAvailableSlotsRequest extends FormRequest
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
            'zone_id' => ['required', 'string', 'max:64'],
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
