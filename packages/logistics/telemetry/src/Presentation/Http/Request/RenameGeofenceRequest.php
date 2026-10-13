<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Validación para renombrar una geocerca: solo el nuevo nombre.
 */
final class RenameGeofenceRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
