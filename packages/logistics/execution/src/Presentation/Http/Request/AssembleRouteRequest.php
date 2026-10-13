<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http\Request;

use Illuminate\Foundation\Http\FormRequest;
use Logistics\Execution\Application\Command\AssembleDailyRoute\AssembleDailyRoute;
use Logistics\Execution\Application\Command\AssembleDailyRoute\RouteStopInput;

/**
 * Validación del armado de la ruta del chofer. Las paradas vienen del plan publicado, ya con su posición
 * en la secuencia y la foto legible del destino (pedido, cliente, dirección, ETA y coordenadas).
 */
final class AssembleRouteRequest extends FormRequest
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
            'route_date' => ['required', 'string', 'date_format:Y-m-d'],
            'stops' => ['required', 'array', 'min:1'],
            'stops.*.shipment_id' => ['required', 'string'],
            'stops.*.sequence' => ['required', 'integer', 'min:1'],
            'stops.*.order_reference' => ['sometimes', 'nullable', 'string'],
            'stops.*.customer_name' => ['sometimes', 'nullable', 'string'],
            'stops.*.address' => ['sometimes', 'nullable', 'string'],
            'stops.*.eta_at' => ['sometimes', 'nullable', 'string'],
            'stops.*.lat' => ['sometimes', 'nullable', 'numeric'],
            'stops.*.lng' => ['sometimes', 'nullable', 'numeric'],
        ];
    }

    public function toCommand(string $vehicleId): AssembleDailyRoute
    {
        /** @var array<string, mixed> $validated */
        $validated = $this->validated();

        /** @var list<array<string, mixed>> $rows */
        $rows = $validated['stops'];

        $stops = [];
        foreach ($rows as $row) {
            $stops[] = new RouteStopInput(
                shipmentId: (string) $row['shipment_id'],
                sequence: (int) $row['sequence'],
                orderReference: self::optionalString($row['order_reference'] ?? null),
                customerName: self::optionalString($row['customer_name'] ?? null),
                address: self::optionalString($row['address'] ?? null),
                etaAt: self::optionalString($row['eta_at'] ?? null),
                lat: isset($row['lat']) ? (float) $row['lat'] : null,
                lng: isset($row['lng']) ? (float) $row['lng'] : null,
            );
        }

        return new AssembleDailyRoute(
            vehicleId: $vehicleId,
            routeDate: (string) $validated['route_date'],
            stops: $stops,
        );
    }

    private static function optionalString(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
