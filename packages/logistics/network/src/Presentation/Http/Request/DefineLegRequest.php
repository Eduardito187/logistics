<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\Network\Domain\ValueObject\CorridorType;

/**
 * Valida el alta de un tramo desde la puerta de integraciones (datos maestros de red).
 *
 * Solo valida la forma primitiva del payload; las reglas de negocio (nodos existentes, sin duplicar,
 * origen distinto de destino) las aplica el handler y el agregado.
 */
final class DefineLegRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'from_node_id' => ['required', 'uuid'],
            'to_node_id' => ['required', 'uuid', 'different:from_node_id'],
            'corridor_type' => ['required', 'string', Rule::in(array_column(CorridorType::cases(), 'value'))],
        ];
    }
}
