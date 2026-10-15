<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\Network\Domain\ValueObject\NodeStatus;
use Logistics\Network\Domain\ValueObject\NodeType;

/**
 * Valida los filtros de la consulta de nodos (puerta app, solo lectura).
 *
 * La autorización por puerta/rol la aplica el middleware que monta el integrador; acá solo se
 * valida la forma de los filtros admitidos: tipo de nodo y estado operativo.
 */
final class IndexNodeRequest extends FormRequest
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
            'type' => ['sometimes', 'string', Rule::in(array_column(NodeType::cases(), 'value'))],
            'status' => ['sometimes', 'string', Rule::in(array_column(NodeStatus::cases(), 'value'))],
        ];
    }

    /**
     * Tipo de nodo pedido, o null si no se filtró por tipo.
     */
    public function typeFilter(): ?string
    {
        $type = $this->query('type');

        return is_string($type) ? $type : null;
    }

    /**
     * Estado pedido, o null si no se filtró por estado.
     */
    public function statusFilter(): ?string
    {
        $status = $this->query('status');

        return is_string($status) ? $status : null;
    }
}
