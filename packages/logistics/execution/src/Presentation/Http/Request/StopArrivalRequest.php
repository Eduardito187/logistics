<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Execution\Domain\ValueObject\ArrivalSource;

/**
 * Validación de la llegada a la parada (POST /app/deliveries/{shipmentId}/arrived). GPS opcional (sin
 * señal no se manda; 0,0 se descarta). `source`: geofence | manual. La deduplicación del reintento la da el
 * header Idempotency-Key (middleware) y, además, la regla de dominio "la primera llegada gana".
 */
final class StopArrivalRequest extends FormRequest
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
        $sources = array_map(static fn (ArrivalSource $source): string => $source->value, ArrivalSource::cases());

        return [
            'occurred_at' => ['required', 'date'],
            'source' => ['required', 'string', 'in:'.implode(',', $sources)],
            'lat' => ['nullable', 'numeric', 'between:-90,90', 'required_with:lng'],
            'lng' => ['nullable', 'numeric', 'between:-180,180', 'required_with:lat'],
            'accuracy_m' => ['nullable', 'numeric', 'min:0'],
        ];
    }
}
