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
     * Normaliza la fecha ANTES de validar: el cliente a veces manda un ISO completo
     * (2026-11-05T00:00:00-04:00); se recorta a la fecha de calendario (Y-m-d) para que la validación
     * `date_format:Y-m-d` acepte ambos formatos sin obligar a la app a recortarla.
     */
    protected function prepareForValidation(): void
    {
        $raw = $this->input('route_date');
        if (is_string($raw) && $raw !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            try {
                $this->merge(['route_date' => (new \DateTimeImmutable($raw))->format('Y-m-d')]);
            } catch (\Throwable) {
                // Si no parsea, se deja tal cual y la validación lo rechaza con un mensaje claro.
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'route_date' => ['required', 'string', 'date_format:Y-m-d'],
            // Paradas OPCIONALES: si no vienen (o llegan vacías), el backend ensambla desde el plan
            // publicado. Si vienen, se usan tal cual (plural `stops`, como en el OpenAPI).
            'stops' => ['sometimes', 'nullable', 'array'],
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
        $rows = is_array($validated['stops'] ?? null) ? $validated['stops'] : [];

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
