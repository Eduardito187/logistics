<?php

declare(strict_types=1);

namespace Logistics\Settings\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Publicación de una nueva versión de una clave de configuración (puerta de integraciones).
 *
 * Valida la forma del request; la regla de negocio (clave namespaced válida, número de versión) la
 * impone el dominio al despachar el comando. `value` debe ser un objeto JSON (arreglo asociativo).
 */
final class SetSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'key' => ['required', 'string', 'max:255', 'regex:/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+$/'],
            'value' => ['required', 'array'],
            'effective_from' => ['nullable', 'date'],
            'created_by' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function key(): string
    {
        return (string) $this->input('key');
    }

    /**
     * @return array<string, mixed>
     */
    public function value(): array
    {
        /** @var array<string, mixed> $value */
        $value = $this->input('value', []);

        return $value;
    }

    public function effectiveFrom(): ?string
    {
        $raw = $this->input('effective_from');

        return is_string($raw) && $raw !== '' ? $raw : null;
    }

    public function createdBy(): ?string
    {
        $raw = $this->input('created_by');

        return is_string($raw) && $raw !== '' ? $raw : null;
    }
}
