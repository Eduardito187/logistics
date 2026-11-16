<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Support\Staff\StaffInbox;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Execution\Application\Contract\LiveRouteStateReader;
use Logistics\Execution\Application\Query\GetDriverRoute\GetDriverRoute;
use Logistics\Execution\Application\ReadModel\DriverRouteView;
use Logistics\Fleet\Application\Query\GetMyCrew\GetMyCrew;
use Logistics\Fleet\Application\ReadModel\MyCrewView;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Resumen COMPACTO del día para el reloj (smartwatch STANDALONE, sin el teléfono vivo): próxima parada,
 * progreso «N de M», avisos sin leer y estado «fuera de ruta». Payload chico a propósito (ahorra batería y
 * datos en la muñeca). El teléfono NO lo necesita (compone el resumen de lo que ya tiene en memoria).
 *
 * Agrega tres contextos por el QueryBus / lecturas de solo lectura, sin acoplar dominios:
 *   - Vehículo del día del usuario autenticado (chofer o ayudante/tripulante): GetMyCrew.
 *   - Ruta del camión: GetDriverRoute (sus conteos ya son item-por-item y ocultan paradas obsoletas).
 *   - Avisos sin leer de la bandeja interna: StaffInbox::unread.
 *
 * Regla de «N de M» (acordada con la app, idéntica a su RouteProgress): N = ATENDIDAS + 1, capado a M, donde
 * ATENDIDAS = entregadas (delivered) + FALLIDAS (failed). Una parada FALLIDA SÍ avanza el contador (el chofer
 * ya pasó por ahí); las REPROGRAMADAS no. Ruta sin pendientes → N = M, próxima parada null, restantes 0.
 */
final class WatchSummaryController extends Controller
{
    private const string APP_TIMEZONE = 'America/La_Paz';

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        $userId = $user instanceof User ? (int) $user->getKey() : 0;

        $summary = [
            'has_route' => false,
            'vehicle_plate' => null,
            'stop_number' => null,
            'stops_total' => null,
            'stops_remaining' => null,
            'next_stop' => null,
            'off_route' => null,
            'unread_notifications' => $userId > 0 ? app(StaffInbox::class)->unread($userId) : 0,
        ];

        // Vínculo con una tripulación: chofer (users.driver_id) o ayudante/tripulante (users.helper_id).
        $driverId = $this->linkId($user instanceof User ? $user->driver_id : null);
        $helperId = $this->linkId($user instanceof User ? $user->helper_id : null);
        if ($driverId === null && $helperId === null) {
            return response()->json(['data' => $summary]);
        }

        $date = $this->date($request);

        $crew = app(QueryBus::class)->ask(new GetMyCrew($driverId, $date, $helperId));
        if (!$crew instanceof MyCrewView) {
            return response()->json(['data' => $summary]);
        }

        $vehicle = $crew->toArray()['vehicle'] ?? null;
        $vehicleId = is_array($vehicle) && is_string($vehicle['id'] ?? null) ? $vehicle['id'] : '';
        $summary['vehicle_plate'] = is_array($vehicle) && is_string($vehicle['plate'] ?? null) ? $vehicle['plate'] : null;
        if ($vehicleId === '') {
            return response()->json(['data' => $summary]);
        }

        $route = app(QueryBus::class)->ask(new GetDriverRoute($vehicleId, $date));
        if (!$route instanceof DriverRouteView) {
            return response()->json(['data' => $summary]);
        }

        $this->fillRoute($summary, $route->toArray());
        $summary['off_route'] = $this->offRoute($vehicleId);

        return response()->json(['data' => $summary]);
    }

    /**
     * Completa el progreso y la próxima parada desde la vista de la ruta (conteos item-por-item, stale-hidden).
     *
     * @param array<string, mixed> $summary
     * @param array<string, mixed> $route
     */
    private function fillRoute(array &$summary, array $route): void
    {
        $total = (int) ($route['total_stops'] ?? 0);
        $remaining = (int) ($route['stops_remaining'] ?? 0);
        $current = is_array($route['current_stop'] ?? null) ? $route['current_stop'] : null;

        $hasPending = $current !== null && $remaining > 0;

        $summary['has_route'] = true;
        $summary['stops_total'] = $total;
        $summary['stops_remaining'] = max(0, $remaining);
        $summary['stop_number'] = self::stopNumber((int) ($route['completed_stops'] ?? 0), (int) ($route['failed_stops'] ?? 0), $total, $hasPending);

        // Ruta sin paradas pendientes (terminada): sin próxima parada.
        if (!$hasPending) {
            $summary['next_stop'] = null;

            return;
        }

        $summary['next_stop'] = [
            'order_reference' => $this->str($current['order_reference'] ?? null),
            'customer_name' => $this->str($current['customer_name'] ?? null),
            'address' => $this->str($current['address'] ?? null),
            'eta_iso' => is_string($current['eta_at'] ?? null) && $current['eta_at'] !== '' ? $current['eta_at'] : null,
        ];
    }

    /**
     * «N de M» (idéntico al RouteProgress de la app): N = ATENDIDAS + 1, capado a M, donde ATENDIDAS =
     * entregadas (delivered) + FALLIDAS (failed). Una parada FALLIDA avanza el contador; las REPROGRAMADAS no
     * (no entran en este cálculo). Sin paradas pendientes (ruta terminada) → N = M. Sin ruta (M≤0) → null.
     */
    public static function stopNumber(int $delivered, int $failed, int $total, bool $hasPending): ?int
    {
        if ($total <= 0) {
            return $hasPending ? 1 : null;
        }
        if (!$hasPending) {
            return $total;
        }

        return min($delivered + $failed + 1, $total);
    }

    /**
     * «Fuera de ruta» según el motor único de ETA: true/false si hay posición del camión; null cuando no hay
     * posiciones (standalone sin GPS, o motor no disponible) — el cliente lo trata como «desconocido».
     */
    private function offRoute(string $vehicleId): ?bool
    {
        try {
            $live = app(LiveRouteStateReader::class)->forVehicle($vehicleId);
        } catch (Throwable) {
            return null;
        }

        if (!is_array($live) || ($live['position'] ?? null) === null) {
            return null;
        }

        return (bool) ($live['off_route'] ?? false);
    }

    /** Vínculo de la cuenta (driver_id / helper_id): string no vacío o null. */
    private function linkId(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function str(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** Fecha a consultar: `?date=YYYY-MM-DD` válida, o HOY en la zona de la app. */
    private function date(Request $request): string
    {
        $raw = $request->query('date');
        if (is_string($raw) && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $raw, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return $raw;
        }

        return (new DateTimeImmutable('now', new DateTimeZone(self::APP_TIMEZONE)))->format('Y-m-d');
    }
}
