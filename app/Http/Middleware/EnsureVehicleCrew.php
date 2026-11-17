<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Models\User;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Guarda de PERTENENCIA por vehículo de la puerta app (alias `vehicle.crew`).
 *
 * Regla del dueño: que no le llegue a pepito la hoja de ruta de juancito. La ruta del camión incluye, por
 * el enriquecimiento, teléfono, dirección y líneas del cliente (PII): solo puede verla —y operar el
 * camión— la OFICINA (admin, supervisor, planificador) o quien sea TRIPULANTE de ESE vehículo el día
 * relevante (el chofer o un ayudante de una tripulación vigente).
 *
 * El vehículo objetivo sale del parámetro de ruta `{vehicleId}`. El día relevante sale de `?date` del
 * request (fecha o fecha-hora ISO 8601, se toma la fecha de calendario tal cual) y, sin él, HOY en la
 * zona de la app (America/La_Paz). La pertenencia se resuelve contra `fleet_crews` (contexto Fleet) por
 * consulta de solo lectura: `users.driver_id` del usuario debe ser el chofer de una tripulación vigente
 * de ese vehículo esa fecha, o estar en sus ayudantes (`helper_ids`). No se duplican reglas de negocio de
 * Fleet: solo pertenencia.
 *
 * Sin pertenencia responde 403 con el contrato de error del proyecto (RFC 7807, motivo en `detail` y en
 * `error.message`) y `code` `out_of_scope_assignment`, coherente con los `out_of_scope_*` de las escrituras
 * de operación. Un `{vehicleId}` sin forma de UUID se rechaza igual con 403 (sin tocar la base, para no
 * disparar un 22P02 contra la columna uuid) y no revela si el vehículo existe.
 *
 * Nota de arquitectura: igual que `order.scope` cruza a Shipments, este middleware vive en el HOST y lee
 * Fleet por query builder. Al extraer Fleet a un servicio, pasará a consultarlo por su API.
 */
final class EnsureVehicleCrew
{
    private const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    private const string APP_TIMEZONE = 'America/La_Paz';

    /** Roles de oficina: ven y operan cualquier vehículo sin ser tripulantes. */
    private const array OFFICE_ROLES = [Role::Admin, Role::Supervisor, Role::Planificador];

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->passes($request, $request->user())) {
            return $next($request);
        }

        return $this->forbidden($request);
    }

    /**
     * ¿Esta petición puede ver / operar el vehículo del path? Oficina siempre; cualquier otro rol solo si es
     * tripulante de ese vehículo (tripulación del día) o CHOFER de un viaje (ruta) de ese vehículo —la
     * asignación es por viaje, no por jornada—. Sin usuario de la app pasa (la autenticación es otra capa de
     * la puerta, no asunto de esta guarda).
     */
    private function passes(Request $request, mixed $user): bool
    {
        if (!$user instanceof User) {
            return true;
        }
        if ($user->hasAnyRole(...self::OFFICE_ROLES)) {
            return true;
        }

        $vehicleId = $this->routeText($request, 'vehicleId');
        $linkIds = $this->userLinkIds($user);
        if ($vehicleId === null || $linkIds === []) {
            return false;
        }

        // Pertenencia por TRIPULACIÓN del día (chofer o ayudante en `fleet_crews` de esa fecha).
        if ($this->isCrewMember($vehicleId, $linkIds, $this->relevantDate($request))) {
            return true;
        }

        // Pertenencia por VIAJE (la asignación es POR VIAJE, no por día): el camión, el chofer y la tripulación
        // se asignan por salida/ruta, no por jornada. Un chofer puede llevar el camión A en un viaje y el B en
        // el siguiente el mismo día; su pertenencia a un camión la define que sea el CHOFER de una ruta (viaje)
        // de ESE camión —abierta, o del día relevante—, leída directo de `execution_driver_routes.driver_id`,
        // sin pasar por la tripulación del día. Así nunca se le dice "este camión no es tuyo" en un viaje suyo.
        if ($this->hasDriverTrip($vehicleId, $linkIds, $request)) {
            return true;
        }

        // Carryover de MEDIANOCHE para el AYUDANTE (la ruta no graba al ayudante): sin `?date` explícito, si el
        // camión tiene una ruta EN CURSO/pausada, el ayudante de la tripulación de ESE día sigue perteneciendo
        // aunque hoy no tenga tripulación. La pertenencia la rige el ESTADO de la ruta, no el calendario.
        if (!$this->hasExplicitDate($request)) {
            $openDay = $this->openRouteDate($vehicleId);

            return $openDay !== null && $this->isCrewMember($vehicleId, $linkIds, $openDay);
        }

        return false;
    }

    /**
     * ¿El usuario es el CHOFER de un VIAJE (ruta) de ese vehículo? La asignación es por viaje: basta con ser el
     * `driver_id` de una ruta de ese camión que esté ABIERTA (en curso/pausada) o, con `?date` explícito, la de
     * esa fecha. Consulta de solo lectura sobre `execution_driver_routes`. (El ayudante no se graba en la ruta;
     * su pertenencia sale de la tripulación, no de aquí.)
     *
     * @param list<string> $linkIds vínculos de la cuenta, en forma de UUID (el `driver_id` es el que matchea)
     */
    private function hasDriverTrip(string $vehicleId, array $linkIds, Request $request): bool
    {
        $date = $this->relevantDate($request);

        try {
            $query = DB::table('execution_driver_routes')
                ->where('vehicle_id', $vehicleId)
                ->whereIn('driver_id', $linkIds);

            if ($this->hasExplicitDate($request)) {
                $query->whereDate('route_date', $date);
            } else {
                $query->where(static function ($inner) use ($date): void {
                    $inner->whereIn('status', ['IN_PROGRESS', 'SUSPENDED'])
                        ->orWhereDate('route_date', $date);
                });
            }

            return $query->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * ¿La petición fijó explícitamente un día por `?date`? En ese caso (previsualizar un día concreto) la
     * pertenencia es estricta a esa fecha y NO aplica el arrastre de la ruta en curso.
     */
    private function hasExplicitDate(Request $request): bool
    {
        $raw = $request->query('date');

        return is_string($raw) && preg_match('/^\d{4}-\d{2}-\d{2}/', trim($raw)) === 1;
    }

    /**
     * Fecha de la ruta EN CURSO (o pausada) más reciente de ese vehículo, cualquier día, o null si no tiene
     * ninguna abierta (o si Ejecución no está disponible). Consulta de solo lectura sobre `execution_driver_routes`.
     */
    private function openRouteDate(string $vehicleId): ?string
    {
        try {
            $date = DB::table('execution_driver_routes')
                ->where('vehicle_id', $vehicleId)
                ->whereIn('status', ['IN_PROGRESS', 'SUSPENDED'])
                ->orderByRaw('route_date DESC NULLS LAST')
                ->orderByDesc('id')
                ->value('route_date');
        } catch (Throwable) {
            return null;
        }

        return is_string($date) && $date !== '' ? substr($date, 0, 10) : null;
    }

    /**
     * ¿El usuario (por su `users.driver_id` como chofer o `users.helper_id` como ayudante) integra una
     * tripulación vigente de ese vehículo esa fecha? Consulta de solo lectura sobre `fleet_crews`.
     *
     * @param list<string> $linkIds vínculos de la cuenta (chofer y/o ayudante), en forma de UUID
     */
    private function isCrewMember(string $vehicleId, array $linkIds, string $date): bool
    {
        try {
            $rows = DB::table('fleet_crews')
                ->where('vehicle_id', $vehicleId)
                ->whereDate('assembled_for', $date)
                ->get(['driver_id', 'helper_ids']);
        } catch (Throwable) {
            // La base no está disponible o la tabla no existe en este entorno: sin pertenencia comprobable.
            return false;
        }

        foreach ($rows as $row) {
            if (in_array((string) $row->driver_id, $linkIds, true)
                || array_intersect($this->helperIds($row->helper_ids ?? null), $linkIds) !== []) {
                return true;
            }
        }

        return false;
    }

    /**
     * Ayudantes de una tripulación: la columna `helper_ids` es jsonb (texto al leer por query builder).
     *
     * @return list<string>
     */
    private function helperIds(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;

        $ids = [];
        foreach (is_array($decoded) ? $decoded : [] as $helperId) {
            if (is_string($helperId) && $helperId !== '') {
                $ids[] = $helperId;
            }
        }

        return $ids;
    }

    /**
     * Vínculos del usuario con una tripulación: su `users.driver_id` (si es chofer) y su `users.helper_id`
     * (si es ayudante / rol tripulante), cada uno solo si tiene forma de UUID. Lista vacía si la cuenta no
     * está ligada a ninguno (entonces no puede integrar ninguna tripulación).
     *
     * @return list<string>
     */
    private function userLinkIds(User $user): array
    {
        $ids = [];
        foreach ([$user->driver_id, $user->helper_id] as $raw) {
            if (is_string($raw) && preg_match(self::UUID_PATTERN, trim($raw)) === 1) {
                $ids[] = strtolower(trim($raw));
            }
        }

        return $ids;
    }

    /**
     * Fecha relevante de la acción: la lee de `?date` (GET de ruta/entregas), o del cuerpo `occurred_at` /
     * `date` (iniciar/cerrar ruta, reportar incidente, armar ruta llevan la fecha en el cuerpo, no en la
     * query), tomando la fecha de calendario tal cual (sin convertir zona). Si ninguna trae una fecha válida,
     * cae a HOY en la zona de la app (el caso normal: el chofer opera la ruta de hoy).
     */
    private function relevantDate(Request $request): string
    {
        foreach ([$request->query('date'), $request->input('occurred_at'), $request->input('date')] as $raw) {
            if (is_string($raw) && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', trim($raw), $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
                return "{$m[1]}-{$m[2]}-{$m[3]}";
            }
        }

        return (new DateTimeImmutable('now', new DateTimeZone(self::APP_TIMEZONE)))->format('Y-m-d');
    }

    private function routeText(Request $request, string $parameter): ?string
    {
        $value = $request->route($parameter);

        if (!is_string($value) || preg_match(self::UUID_PATTERN, trim($value)) !== 1) {
            return null;
        }

        return strtolower(trim($value));
    }

    private function forbidden(Request $request): Response
    {
        return ProblemDetails::fromCode(
            ErrorCode::OutOfScopeAssignment,
            'You are not assigned to the crew of this vehicle for the requested day.',
        )->toResponse($request);
    }
}
