<?php

declare(strict_types=1);

namespace Logistics\Inbound\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación de entrada para agendar la cita de un ASN.
 */
final class ScheduleAsnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'appointment_date' => ['required', 'date_format:Y-m-d'],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i'],
        ];
    }
}
