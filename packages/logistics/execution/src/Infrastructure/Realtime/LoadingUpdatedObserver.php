<?php

declare(strict_types=1);

namespace Logistics\Execution\Infrastructure\Realtime;

use Illuminate\Support\Facades\Cache;
use Logistics\Outbox\Application\Contract\OutboxObserver;
use Logistics\Outbox\Domain\OutboxMessage;
use Throwable;

/**
 * Observador del outbox: cuando pasa un hecho que cambia la carga de un camión (lo que se está cargando o la
 * lista de entregas asignadas), encola la señal de tiempo real `loading.updated` para el andén de esa ciudad,
 * la oficina y el chofer del camión.
 *
 * Es un observador (no un reactor): no participa del desenlace del mensaje. Solo ENCOLA
 * {@see BroadcastLoadingUpdated}; no consulta la base ni habla con el servidor de sockets. Resolver los
 * canales (ciudad y chofer) es trabajo de la cola.
 *
 *  - Anti-ráfaga POR VEHÍCULO: varios hechos seguidos de la carga de un mismo camión se avisan una sola vez
 *    por ventana corta (`Cache::add` + el trabajo demorado esa misma ventana, para que al llegar la señal la
 *    hoja de carga ya incluya toda la ráfaga).
 *  - Verlo más de una vez (reintentos del outbox) es inocuo: la señal es «volvé a pedir la hoja de carga».
 *  - Nunca lanza: una carga incompleta se ignora y cualquier error queda en el log.
 *  - Sin transporte de tiempo real (`BROADCAST_CONNECTION=null|log`) no hace nada.
 */
final readonly class LoadingUpdatedObserver implements OutboxObserver
{
    /**
     * Hechos que cambian la carga / la lista de entregas de un camión.
     *
     * @var list<string>
     */
    public const EVENTS = [
        // Escaneo de carga validada de un bulto al camión (primer bulto del envío → la entrega pasa a cargada).
        'execution.shipment_loaded',
        // Cada bulto siguiente cargado (señal liviana por escaneo): refresca el andén en vivo sin esperar al
        // primero. Trae `shipment_id` y `vehicle_id` en su carga, como los demás hechos de carga.
        'execution.unit_loaded',
        // Lista de entregas del camión: envío abierto en un camión (asignar vehículo), movido de camión,
        // parada sumada o quitada de su ruta.
        'execution.delivery_opened',
        'execution.delivery_vehicle_reassigned',
        'execution.route_stop_attached',
        'execution.route_stop_detached',
        // Ruta del camión armada/editada: cambió su lista de entregas en general (sin un envío puntual).
        'execution.driver_route_assembled',
    ];

    private const KEY_PREFIX = 'execution:realtime:loading-signal:';

    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    public function observe(OutboxMessage $message): void
    {
        try {
            if (in_array($message->eventName(), self::EVENTS, true) && LoadingRealtime::enabled()) {
                $this->signal($message->payload());
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function signal(array $payload): void
    {
        $shipmentId = self::uuid($payload['shipment_id'] ?? null);
        $window = LoadingRealtime::debounceSeconds();

        // Un hecho puede tocar más de un camión (reasignación: `from`/`to`); cada uno con su propia ventana.
        $vehicleIds = [];
        foreach (['vehicle_id', 'from_vehicle_id', 'to_vehicle_id'] as $field) {
            $vehicleId = self::uuid($payload[$field] ?? null);
            if ($vehicleId !== null
                && !in_array($vehicleId, $vehicleIds, true)
                && Cache::add(self::KEY_PREFIX.$vehicleId, true, $window)) {
                $vehicleIds[] = $vehicleId;
            }
        }

        // Sin camión al que avisar (o todos dentro de su ventana) no hay señal que encolar.
        if ($vehicleIds !== []) {
            BroadcastLoadingUpdated::dispatch($vehicleIds, $shipmentId)->delay($window);
        }
    }

    /**
     * Solo UUIDs con forma válida: en la cola se comparan contra columnas `uuid` y PostgreSQL rechaza
     * cualquier otro texto.
     */
    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1 ? strtolower($value) : null;
    }
}
