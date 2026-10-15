<?php

declare(strict_types=1);

namespace Logistics\Capacity\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Valida la consulta del calendario de cupos: una fecha (YYYY-MM-DD). La autorización por puerta la
 * aplica el middleware del integrador.
 */
final class CapacityCalendarRequest extends FormRequest
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
            'date' => ['required', 'date_format:Y-m-d'],
            'municipality_id' => ['nullable', 'uuid'],
        ];
    }

    /**
     * Fecha validada del calendario (YYYY-MM-DD). No se llama date() para no chocar con
     * Illuminate\Http\Request::date(), que tiene otra firma y devuelve un Carbon.
     */
    public function calendarDate(): string
    {
        return (string) $this->input('date');
    }

    /**
     * municipality_id para filtrar el calendario por municipio (null = todos los scopes del día).
     */
    public function municipalityId(): ?string
    {
        $value = $this->input('municipality_id');

        return is_string($value) && $value !== '' ? $value : null;
    }
}
