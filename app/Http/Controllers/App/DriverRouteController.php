<?php

declare(strict_types=1);

namespace App\Http\Controllers\App;

use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * «Mi ruta de hoy» — pantalla principal de la app de campo del chofer: sus paradas del día en orden, con
 * cliente, dirección, estado (entregada/pendiente) y el trazo de la ruta pegado a calles para el mapa.
 * Lectura directa del read-side de ejecución/planificación (misma data que el mapa del admin), acotada
 * al chofer autenticado. Sin UUID visible: pedido por referencia, vehículo por placa.
 */
final class DriverRouteController
{
    public function __invoke(): InertiaResponse
    {
        $user = Auth::user();
        $driverId = $user instanceof User ? $user->driver_id : null;
        $today = (new DateTimeImmutable('today'))->format('Y-m-d');

        if (!is_string($driverId) || $driverId === '') {
            return Inertia::render('Driver/Today', ['hasRoute' => false, 'driverName' => $this->driverName($user)]);
        }

        $route = DB::table('execution_driver_routes')
            ->where('driver_id', $driverId)
            ->whereDate('route_date', $today)
            ->orderByDesc('updated_at')
            ->first(['id', 'vehicle_id', 'status']);

        if ($route === null) {
            return Inertia::render('Driver/Today', ['hasRoute' => false, 'driverName' => $this->driverName($user)]);
        }

        $plate = DB::table('fleet_vehicles')->where('id', $route->vehicle_id)->value('plate');

        /** @var Collection<int, \stdClass> $stopRows */
        $stopRows = DB::table('execution_driver_route_stops')
            ->where('route_id', $route->id)
            ->orderBy('sequence')
            ->get(['shipment_id', 'sequence', 'order_reference', 'customer_name', 'address', 'eta', 'lat', 'lng', 'status', 'settled_at']);

        $stops = [];
        foreach ($stopRows as $s) {
            $done = ($s->status ?? null) === 'COMPLETED' || ($s->settled_at ?? null) !== null;
            $stops[] = [
                'shipment_id' => (string) $s->shipment_id,
                'sequence' => (int) ($s->sequence ?? 0),
                'order' => is_string($s->order_reference ?? null) ? $s->order_reference : '',
                'customer' => is_string($s->customer_name ?? null) ? $s->customer_name : '',
                'address' => is_string($s->address ?? null) ? $s->address : '',
                'eta' => $s->eta !== null ? (string) $s->eta : null,
                'lat' => $s->lat !== null ? (float) $s->lat : null,
                'lng' => $s->lng !== null ? (float) $s->lng : null,
                'done' => $done,
            ];
        }

        $delivered = count(array_filter($stops, static fn (array $s): bool => $s['done']));

        return Inertia::render('Driver/Today', [
            'hasRoute' => true,
            'driverName' => $this->driverName($user),
            'plate' => is_string($plate) ? $plate : '—',
            'status' => is_string($route->status) ? $route->status : '',
            'stops' => $stops,
            'summary' => ['total' => count($stops), 'delivered' => $delivered, 'pending' => count($stops) - $delivered],
            'routeLine' => $this->routeLine((string) $route->vehicle_id, $today),
        ]);
    }

    private function driverName(mixed $user): string
    {
        return $user instanceof User && is_string($user->name) ? $user->name : 'Chofer';
    }

    /**
     * Trazo de la ruta (geometría del plan, pegada a calles) como [[lat,lng],...] para el mapa. [] si no hay.
     *
     * @return list<array{0: float, 1: float}>
     */
    private function routeLine(string $vehicleId, string $today): array
    {
        try {
            $raw = DB::table('planning_plans')
                ->where('vehicle_id', $vehicleId)
                ->whereDate('plan_date', $today)
                ->orderByDesc('updated_at')
                ->value('route_geometry');

            if (!is_string($raw) || $raw === '') {
                return [];
            }

            /** @var array{coordinates?: list<array{0: float, 1: float}>}|null $geo */
            $geo = json_decode($raw, true);
            $coords = is_array($geo) && isset($geo['coordinates']) && is_array($geo['coordinates']) ? $geo['coordinates'] : [];

            $out = [];
            foreach ($coords as $c) {
                if (is_array($c) && isset($c[0], $c[1]) && is_numeric($c[0]) && is_numeric($c[1])) {
                    $out[] = [(float) $c[1], (float) $c[0]];
                }
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }
}
