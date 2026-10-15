<?php

declare(strict_types=1);

namespace Logistics\Network\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Logistics\Network\Domain\ValueObject\Department;

/**
 * Valida la consulta de corredores operativos por departamento (puerta app, solo lectura).
 *
 * El departamento es obligatorio: la consulta siempre se hace para un departamento concreto.
 */
final class IndexOperationalCorridorRequest extends FormRequest
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
            'department' => ['required', 'string', Rule::in(array_column(Department::cases(), 'value'))],
        ];
    }

    /**
     * Departamento pedido (value del enum Department), ya validado.
     */
    public function department(): string
    {
        $department = $this->query('department');

        return is_string($department) ? $department : '';
    }
}
