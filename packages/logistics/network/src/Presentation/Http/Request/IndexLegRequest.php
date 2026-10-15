<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\Network\Domain\ValueObject\CorridorType;

/**
 * Valida el filtro de la consulta de tramos (puerta app, solo lectura).
 *
 * La autorización por puerta/rol la aplica el middleware que monta el integrador; acá solo se valida
 * la forma del filtro admitido: tipo de corredor.
 */
final class IndexLegRequest extends FormRequest
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
            'corridor_type' => ['sometimes', 'string', Rule::in(array_column(CorridorType::cases(), 'value'))],
        ];
    }

    /**
     * Tipo de corredor pedido, o null si no se filtró.
     */
    public function corridorFilter(): ?string
    {
        $corridor = $this->query('corridor_type');

        return is_string($corridor) ? $corridor : null;
    }
}
