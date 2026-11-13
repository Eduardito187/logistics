<?php

declare(strict_types=1);

namespace Logistics\Execution\Presentation\Http;

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Logistics\Execution\Application\Query\GetDeliveryState\GetDeliveryState;
use Logistics\Execution\Application\Query\GetNodeReception\GetNodeReception;
use Logistics\Execution\Application\ReadModel\DeliveryView;
use Logistics\Execution\Application\ReadModel\NodeReceptionView;
use Logistics\Labels\Application\Query\ResolveLabelUnit\ResolveLabelUnit;
use Logistics\Labels\Application\ReadModel\LabelUnitView;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Base de los controllers HTTP del contexto Ejecución.
 *
 * La capa de Presentation solo habla con Application a través de los buses CQRS: nunca con
 * Infrastructure ni con el dominio directo. Aquí viven el envelope de respuesta común y la
 * resolución del actor (usuario + dispositivo) para dejar los endpoints concretos limpios.
 */
abstract class ExecutionController
{
    public function __construct(
        protected readonly CommandBus $commandBus,
        protected readonly QueryBus $queryBus,
    ) {}

    /**
     * Respuesta de error con FORMATO ÚNICO en toda la API (decisión del dueño): el motivo viaja SIEMPRE
     * tanto en `detail` como en `error.message`, con un `code` estable, igual que el renderer global
     * RFC 7807. Así el app muestra el porqué del fallo leyendo cualquiera de los dos campos y ninguna API
     * responde distinto a otra.
     */
    protected function problem(string $code, string $message, int $status): JsonResponse
    {
        return new JsonResponse([
            'type' => rtrim((string) config('api.problem_type_base_uri', '/problems'), '/').'/'.$code,
            'title' => ucfirst(str_replace('_', ' ', $code)),
            'status' => $status,
            'code' => $code,
            'detail' => $message,
            'error' => ['code' => $code, 'message' => $message],
        ], $status, ['Content-Type' => 'application/problem+json']);
    }

    /**
     * Resuelve la clave de idempotencia: primero el header `Idempotency-Key` (la app offline la fija
     * por escaneo), con respaldo en el cuerpo. Es un contrato con la app: el reenvío del mismo
     * escaneo no duplica el hecho.
     */
    protected function idempotencyKey(Request $request): string
    {
        $header = $request->header('Idempotency-Key');
        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        $body = $request->input('idempotency_key');

        return is_string($body) ? trim($body) : '';
    }

    /**
     * Identidad del dispositivo que origina el escaneo.
     *
     * Fuente AUTORITATIVA: el dispositivo ligado al token Sanctum (`PersonalAccessToken.device_id`), que es
     * lo que `EnsureDeviceToken` valida en cada request de `/app/*`. El integrador de Auth (futuro) lo
     * inyectará como atributo de la request; mientras no exista, se resuelve acá desde el token. Respaldos:
     * el header `X-Device-Id` que manda la app y, por compatibilidad, el cuerpo. El dominio exige que no sea
     * vacío (DeviceContext), así que esta resolución es la que evita que TODO escaneo (POD/carga/…) falle.
     */
    protected function deviceId(Request $request): string
    {
        $attribute = $request->attributes->get('device_id');
        if (is_string($attribute) && trim($attribute) !== '') {
            return trim($attribute);
        }

        $user = $request->user();
        if ($user instanceof User) {
            $token = $user->currentAccessToken();
            if ($token instanceof PersonalAccessToken && $token->device_id !== null) {
                return (string) $token->device_id;
            }
        }

        $header = $request->header('X-Device-Id');
        if (is_string($header) && trim($header) !== '') {
            return trim($header);
        }

        $body = $request->input('device_id');

        return is_string($body) ? trim($body) : '';
    }

    /**
     * Identidad del usuario que origina el escaneo (ver deviceId()).
     *
     * Fuente AUTORITATIVA: el usuario autenticado, que el token Sanctum ya prueba (`getAuthIdentifier()`);
     * no tiene sentido exigir que el cliente mande su propio id. Respaldo por compatibilidad: el cuerpo.
     */
    protected function userId(Request $request): string
    {
        $attribute = $request->attributes->get('user_id');
        if (is_string($attribute) && trim($attribute) !== '') {
            return trim($attribute);
        }

        $userId = $request->user()?->getAuthIdentifier();
        if ($userId !== null && trim((string) $userId) !== '') {
            return trim((string) $userId);
        }

        $body = $request->input('user_id');

        return is_string($body) ? trim($body) : '';
    }

    /**
     * Normaliza los identificadores de bulto que la app manda tras ESCANEAR la etiqueta.
     *
     * El chofer entrega (y prepara, carga, recibe) escaneando la ETIQUETA, pero el QR no lleva el
     * `shipment_unit.id`: la app manda el `label_id` (ULID de la etiqueta) como `unit_id`/`unit_ids[]`. Si se
     * guardara crudo nunca matchearía con `shipment_units.id` (UUID v7) y el ciclo de vida de la etiqueta y
     * los readers por unidad no encontrarían el escaneo. Acá, en el borde, se resuelve cada id contra la
     * etiqueta ({@see ResolveLabelUnit}): si resuelve a un bulto se reemplaza por el `shipment_unit.id`
     * canónico; si no resuelve (ya es un id de bulto, o es desconocido) se deja tal cual.
     *
     * Offline-first: si la resolución lanza NO se bloquea el escaneo; se usa el id crudo y se sigue.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function resolveScannedUnitIds(array $data): array
    {
        if (isset($data['unit_id']) && is_scalar($data['unit_id'])) {
            $data['unit_id'] = $this->resolveScannedUnitId((string) $data['unit_id']);
        }

        if (isset($data['unit_ids']) && is_array($data['unit_ids'])) {
            $resolved = [];
            foreach ($data['unit_ids'] as $raw) {
                if (!is_scalar($raw)) {
                    continue;
                }

                $id = $this->resolveScannedUnitId((string) $raw);
                if ($id !== '' && !in_array($id, $resolved, true)) {
                    $resolved[] = $id;
                }
            }

            $data['unit_ids'] = $resolved;
        }

        return $data;
    }

    /**
     * Resuelve UN id escaneado: `label_id` → `shipment_unit.id` canónico si la etiqueta resuelve; si no,
     * devuelve el id tal cual (ya era un bulto, o es desconocido). Nunca lanza (offline-first).
     */
    private function resolveScannedUnitId(string $scannedId): string
    {
        $scannedId = trim($scannedId);
        if ($scannedId === '') {
            return '';
        }

        try {
            $view = $this->queryBus->ask(new ResolveLabelUnit($scannedId));
            if ($view instanceof LabelUnitView && $view->resolved && $view->unitId !== null) {
                return $view->unitId;
            }
        } catch (Throwable $exception) {
            report($exception);
        }

        return $scannedId;
    }

    /**
     * Respuesta estándar tras un escaneo: devuelve el estado actual de la entrega del envío.
     *
     * Así la app offline reconcilia su vista local con la fuente de verdad en una sola llamada.
     */
    protected function deliveryStateResponse(string $shipmentId, int $status = 200): JsonResponse
    {
        /** @var DeliveryView|null $view */
        $view = $this->queryBus->ask(new GetDeliveryState($shipmentId));

        return new JsonResponse(['data' => $view?->toArray()], $status);
    }

    /**
     * Respuesta estándar tras una operación de recepción en nodo: devuelve el estado actual de la
     * recepción del envío en ese nodo (esperados, recibidos, faltantes, sobrantes) en una sola llamada.
     */
    protected function nodeReceptionResponse(string $shipmentId, string $destinationNodeId, int $status = 200): JsonResponse
    {
        /** @var NodeReceptionView|null $view */
        $view = $this->queryBus->ask(new GetNodeReception($shipmentId, $destinationNodeId));

        return new JsonResponse(['data' => $view?->toArray()], $status);
    }
}
