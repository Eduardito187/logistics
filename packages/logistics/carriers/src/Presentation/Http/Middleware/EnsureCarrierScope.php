<?php

declare(strict_types=1);

namespace Logistics\Carriers\Presentation\Http\Middleware;

use App\Enums\Role;
use App\Http\Problem\ErrorCode;
use App\Http\Problem\ProblemDetails;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Logistics\Carriers\Application\Query\GetCarrierTrip\GetCarrierTrip;
use Logistics\Carriers\Application\Query\GetSettlement\GetSettlement;
use Logistics\Carriers\Application\ReadModel\CarrierTripView;
use Logistics\Carriers\Application\ReadModel\SettlementView;
use Logistics\Shared\Application\Query\QueryBus;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Autorización SERVER-SIDE de los endpoints de transportistas de la puerta app (`/app/carriers/*`).
 *
 * Antes el backend confiaba en el `carrier_id` que mandaba la app (el usuario transportista elegía
 * "quién soy" de una lista de TODOS los transportistas), así que podía leer, aprobar, objetar o pagar
 * liquidaciones AJENAS. Ahora la identidad del transportista sale SOLO de `users.carrier_id`.
 *
 * Matriz por rol:
 *   - Oficina (admin / supervisor): acceso cross-transportista, pasa directo.
 *   - Transportista: acotado a SU transportista (`users.carrier_id`). Sin vínculo → 403 en cualquier endpoint.
 *   - Cualquier otro rol (chofer, preparador, tienda) o sin usuario: 403 (seguro por defecto).
 *
 * Modos (parámetro del middleware en la ruta):
 *   - `list`       → listados: se IGNORA el `?carrier_id=` del cliente y se fuerza el del usuario.
 *   - `settlement` → la liquidación de la ruta (`{settlementId}`) debe ser del transportista del usuario;
 *                    si no existe o es ajena → 403 (sin revelar si existe).
 *   - `carrier`    → el `{carrierId}` de la ruta debe ser el del usuario.
 *   - `trip`       → el viaje de la ruta (`{tripId}`) debe ser del transportista del usuario; si no existe
 *                    o es ajeno → 403 (sin revelar si existe). Oficina pasa (y recibe 404 si no existe).
 *   - `office`     → acción reservada a oficina: el transportista recibe 403.
 *
 * Además deja el id del transportista acotado en el atributo {@see self::ATTRIBUTE} del request para que
 * el controller filtre lo que no pasa por la consulta (p. ej. el listado de transportistas).
 *
 * Respuesta de error: 403 RFC 7807 vía ProblemDetails, igual que el alcance por tienda.
 */
final class EnsureCarrierScope
{
    public const MODE_LIST = 'list';

    public const MODE_SETTLEMENT = 'settlement';

    public const MODE_CARRIER = 'carrier';

    public const MODE_OFFICE = 'office';

    public const MODE_TRIP = 'trip';

    /**
     * Atributo del request con el UUID del transportista al que quedó acotada la petición (solo rol
     * transportista; ausente para oficina).
     */
    public const ATTRIBUTE = 'carriers.scope_carrier_id';

    public function __construct(
        private readonly QueryBus $queries,
    ) {}

    public function handle(Request $request, Closure $next, string $mode): Response
    {
        $user = $request->user();

        // Oficina opera cross-transportista (revisión, aprobación y pago de liquidaciones).
        if ($user instanceof User && $user->hasAnyRole(Role::Admin, Role::Supervisor)) {
            return $next($request);
        }

        $denied = $this->carrierDenial($request, $user, $mode);
        if ($denied !== null) {
            return $denied;
        }

        // Llegar acá implica usuario transportista con vínculo (carrierDenial ya lo verificó).
        $carrierId = $user instanceof User ? (string) $user->carrier_id : '';

        if ($mode === self::MODE_LIST) {
            // Se ignora cualquier carrier_id del cliente: el filtro es SIEMPRE el del usuario.
            $request->query->set('carrier_id', $carrierId);
        }

        $request->attributes->set(self::ATTRIBUTE, $carrierId);

        return $next($request);
    }

    /**
     * Respuesta 403 si el usuario (no oficina) no puede operar en este modo; null si está permitido.
     * Mismo orden de chequeos que antes: sin usuario/rol → sin vínculo → alcance por modo.
     */
    private function carrierDenial(Request $request, mixed $user, string $mode): ?Response
    {
        // Fuera de oficina, solo el rol transportista llega a estos endpoints.
        if (!$user instanceof User || !$user->hasRole(Role::Transportista)) {
            return $this->forbidden($request, 'This action requires an office or carrier account.');
        }

        $carrierId = $user->carrier_id;

        // Transportista sin vínculo: no puede ver ni operar nada (seguro por defecto).
        if ($carrierId === null || $carrierId === '') {
            return $this->forbidden($request, 'Your account is not linked to a carrier.');
        }

        return $this->modeDenial($request, $carrierId, $mode);
    }

    /**
     * Respuesta 403 si el recurso de la ruta no es del transportista del usuario; null si está permitido.
     */
    private function modeDenial(Request $request, string $carrierId, string $mode): ?Response
    {
        $allowed = match ($mode) {
            self::MODE_LIST => true,
            self::MODE_SETTLEMENT => $this->settlementCarrierId($request) === $carrierId,
            self::MODE_CARRIER => $this->routeString($request, 'carrierId') === $carrierId,
            self::MODE_TRIP => $this->tripCarrierId($request) === $carrierId,
            // `office` y cualquier modo desconocido: denegado para el transportista.
            default => false,
        };

        if ($allowed) {
            return null;
        }

        return $this->forbidden(
            $request,
            $mode === self::MODE_OFFICE
                ? 'This action is reserved to office roles.'
                : 'This action is restricted to your own carrier.',
        );
    }

    /**
     * Transportista dueño de la liquidación de la ruta, leído por la consulta GetSettlement (Presentation →
     * Application). Null si no existe o si el id es inválido: ambos casos terminan en 403.
     */
    private function settlementCarrierId(Request $request): ?string
    {
        $settlementId = $this->routeString($request, 'settlementId');

        if ($settlementId === null) {
            return null;
        }

        try {
            $settlement = $this->queries->ask(new GetSettlement(settlementId: $settlementId));
        } catch (Throwable) {
            return null;
        }

        return $settlement instanceof SettlementView ? $settlement->carrierId : null;
    }

    /**
     * Transportista dueño del viaje de la ruta, leído por la consulta GetCarrierTrip (Presentation →
     * Application). Null si no existe o si el id es inválido: ambos casos terminan en 403.
     */
    private function tripCarrierId(Request $request): ?string
    {
        $tripId = $this->routeString($request, 'tripId');

        if ($tripId === null) {
            return null;
        }

        try {
            $trip = $this->queries->ask(new GetCarrierTrip(tripId: $tripId));
        } catch (Throwable) {
            return null;
        }

        return $trip instanceof CarrierTripView ? $trip->carrierId : null;
    }

    private function routeString(Request $request, string $parameter): ?string
    {
        $value = $request->route($parameter);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function forbidden(Request $request, string $detail): Response
    {
        return ProblemDetails::fromCode(ErrorCode::Forbidden, $detail)->toResponse($request);
    }
}
