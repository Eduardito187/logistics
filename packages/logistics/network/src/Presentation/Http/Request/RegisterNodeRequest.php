<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\Network\Domain\ValueObject\NodeType;

/**
 * Valida el alta de un nodo desde la puerta de integraciones (sincronización de datos maestros SAP).
 *
 * Solo valida la forma primitiva del payload; las reglas de negocio (código único, etc.) las aplica
 * el agregado dentro del handler del comando.
 */
final class RegisterNodeRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'string', Rule::in(array_column(NodeType::cases(), 'value'))],
            'latitude' => ['required', 'numeric', 'between:-90,90'],
            'longitude' => ['required', 'numeric', 'between:-180,180'],
        ];
    }
}
