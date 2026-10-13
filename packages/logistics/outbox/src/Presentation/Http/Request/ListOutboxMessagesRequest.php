<?php

declare(strict_types=1);

namespace Logistics\Outbox\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\Outbox\Domain\ValueObject\OutboxStatus;

/**
 * Validación del listado del outbox: filtro opcional por estado y paginación.
 *
 * La autorización por puerta/rol la aplica el middleware que monta el integrador; acá authorize()
 * devuelve true y solo se valida la forma de la entrada.
 */
final class ListOutboxMessagesRequest extends FormRequest
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
            'status' => ['nullable', 'string', Rule::in(array_column(OutboxStatus::cases(), 'value'))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function statusFilter(): ?string
    {
        $value = $this->input('status');

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function page(): int
    {
        return (int) ($this->input('page') ?? 1);
    }

    public function perPage(): int
    {
        return (int) ($this->input('per_page') ?? 25);
    }
}
