<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Http\Request;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\Capacity\Domain\ValueObject\CapacityUnit;

/**
 * Validación base de la clave natural de un cupo: scope (nodo XOR zona), fecha, franja y unidad.
 *
 * La autorización por puerta/rol la aplica el middleware que monta el integrador; acá authorize()
 * devuelve true y solo se valida la forma de la entrada (igual que en los demás contextos).
 */
abstract class BucketIdentityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reglas comunes de la clave natural del bucket.
     *
     * @return array<string, list<mixed>>
     */
    protected function identityRules(): array
    {
        return [
            'node_id' => ['nullable', 'uuid', 'required_without:zone_id'],
            'zone_id' => ['nullable', 'uuid', 'required_without:node_id'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time_slot' => ['required', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d-([01]\d|2[0-3]):[0-5]\d$/'],
            'unit' => ['required', 'string', Rule::in(array_column(CapacityUnit::cases(), 'value'))],
        ];
    }

    /**
     * Un cupo pertenece a un nodo O a una zona, nunca a ambos (invariante del dominio BucketScope).
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->filled('node_id') && $this->filled('zone_id')) {
                $validator->errors()->add('zone_id', 'A capacity bucket belongs to a node or a zone, not both.');
            }
        });
    }

    /**
     * node_id normalizado (null si no se envió).
     */
    public function nodeId(): ?string
    {
        $value = $this->input('node_id');

        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * zone_id normalizado (null si no se envió).
     */
    public function zoneId(): ?string
    {
        $value = $this->input('zone_id');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
