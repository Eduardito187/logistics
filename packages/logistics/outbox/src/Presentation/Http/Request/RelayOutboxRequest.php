<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación del disparo manual de un lote del relay: tamaño de lote opcional (acotado).
 */
final class RelayOutboxRequest extends FormRequest
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
            'limit' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ];
    }

    public function limit(?int $default): ?int
    {
        $value = $this->input('limit');

        return $value === null ? $default : (int) $value;
    }
}
