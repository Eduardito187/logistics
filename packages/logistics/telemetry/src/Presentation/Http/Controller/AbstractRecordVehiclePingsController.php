<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Validator;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Command\RecordVehiclePings\RecordVehiclePings;
use Logistics\Telemetry\Application\Query\ClassifyPingFixes\ClassifyPingFixes;
use Logistics\Telemetry\Presentation\Http\ApiController;
use Logistics\Telemetry\Presentation\Http\Request\RecordVehiclePingsRequest;

/**
 * Ingiere un lote de lecturas GPS de un vehículo despachando el comando RecordVehiclePings.
 *
 * Es el camino RECOMENDADO para la app (auditoría #12): un POST por lote (hasta `ingest.max_batch` lecturas),
 * en vez de un POST por lectura. Alimenta posición en vivo, trips, geocercas/alertas, desvío/exceso sostenido,
 * migas de pan por envío y —vía outbox— el seguimiento por envío.
 *
 * Resultado POR ÍTEM (`data.results[]`, en el orden del lote):
 *  - `accepted`  → lectura nueva, ingerida;
 *  - `duplicate` → ya recibida (mismo `fix_id`, o mismo `device_id` + `recorded_at`): idempotente, no se repite;
 *  - `rejected`  → mal formada (con `errors`); no se ingiere, el resto del lote sí.
 * Estado: 202 si no hubo rechazos; 207 (Multi-Status) si hubo rechazos y algo se aceptó o era duplicado; 422 si
 * se rechazó todo. El orden de las lecturas no importa (se ordenan por `recorded_at`).
 *
 * El `source` (app vs integración) lo fija cada subclase según la puerta, no el cuerpo.
 */
abstract class AbstractRecordVehiclePingsController extends ApiController
{
    public function __construct(
        private readonly CommandBus $commands,
        private readonly QueryBus $queries,
    ) {}

    /**
     * Origen de las lecturas: 'app' o 'integration'. Lo define la subclase según su puerta.
     */
    abstract protected function source(): string;

    public function __invoke(RecordVehiclePingsRequest $request): JsonResponse
    {
        return $this->guard(function () use ($request): JsonResponse {
            $vehicleId = $request->string('vehicle_id')->toString();
            $driverId = $request->input('driver_id');
            $routeId = $request->input('route_id');
            $items = array_values($request->array('pings'));

            [$valid, $rejected] = $this->validateItems($items);

            $fixes = [];
            foreach ($valid as $index => $ping) {
                $fix = ['index' => $index, 'recorded_at' => $ping['recorded_at']];
                if (isset($ping['fix_id'])) {
                    $fix['fix_id'] = $ping['fix_id'];
                }
                if (isset($ping['device_id'])) {
                    $fix['device_id'] = $ping['device_id'];
                }
                $fixes[] = $fix;
            }

            /** @var array<int, string> $statuses */
            $statuses = $fixes === [] ? [] : $this->queries->ask(new ClassifyPingFixes($vehicleId, $fixes));

            if ($valid !== []) {
                $this->commands->dispatch(new RecordVehiclePings(
                    vehicleId: $vehicleId,
                    driverId: is_string($driverId) && $driverId !== '' ? $driverId : null,
                    routeId: is_string($routeId) && $routeId !== '' ? $routeId : null,
                    source: $this->source(),
                    pings: array_values($valid),
                ));
            }

            $results = [];
            $counts = ['accepted' => 0, 'duplicate' => 0, 'rejected' => 0];
            foreach ($items as $index => $raw) {
                $fixId = is_array($raw) && is_scalar($raw['fix_id'] ?? null) ? (string) $raw['fix_id'] : null;
                if (isset($rejected[$index])) {
                    $results[] = ['index' => $index, 'fix_id' => $fixId, 'status' => 'rejected', 'errors' => $rejected[$index]];
                    $counts['rejected']++;

                    continue;
                }

                $status = $statuses[$index] ?? 'accepted';
                $results[] = ['index' => $index, 'fix_id' => $fixId, 'status' => $status];
                $counts[$status === 'duplicate' ? 'duplicate' : 'accepted']++;
            }

            $body = [
                'vehicle_id' => $vehicleId,
                'source' => $this->source(),
                'count' => count($items),
                'accepted_count' => $counts['accepted'],
                'duplicate_count' => $counts['duplicate'],
                'rejected_count' => $counts['rejected'],
                'results' => $results,
            ];

            if ($counts['rejected'] === count($items)) {
                return $this->error('pings_rejected', 'Every ping in the batch was rejected.', 422, $body);
            }
            if ($counts['rejected'] > 0) {
                return new JsonResponse(['data' => ['accepted' => true] + $body], 207);
            }

            return $this->accepted($body);
        });
    }

    /**
     * Valida cada lectura por separado y la normaliza a la forma primitiva que espera el comando.
     *
     * @param list<mixed> $items
     *
     * @return array{0: array<int, array{lat: float, lng: float, recorded_at: string, speed_kph?: float, heading?: int, accuracy_m?: float, ignition?: bool, odometer_m?: int, device_id?: string, fix_id?: string}>, 1: array<int, array<string, list<string>>>}
     */
    private function validateItems(array $items): array
    {
        $valid = [];
        $rejected = [];
        $rules = RecordVehiclePingsRequest::itemRules();

        foreach ($items as $index => $row) {
            if (!is_array($row)) {
                $rejected[$index] = ['ping' => ['The ping must be an object.']];

                continue;
            }

            $validator = Validator::make($row, $rules);
            if ($validator->fails()) {
                /** @var array<string, list<string>> $messages */
                $messages = $validator->errors()->toArray();
                $rejected[$index] = $messages;

                continue;
            }

            $valid[$index] = $this->normalize($row);
        }

        return [$valid, $rejected];
    }

    /**
     * Posición e instante obligatorios; el resto de la telemetría solo si vino en la petición.
     *
     * @param array<mixed> $row
     *
     * @return array{lat: float, lng: float, recorded_at: string, speed_kph?: float, heading?: int, accuracy_m?: float, ignition?: bool, odometer_m?: int, device_id?: string, fix_id?: string}
     */
    private function normalize(array $row): array
    {
        $ping = [
            'lat' => (float) $row['lat'],
            'lng' => (float) $row['lng'],
            'recorded_at' => (string) $row['recorded_at'],
        ];

        if (isset($row['speed_kph'])) {
            $ping['speed_kph'] = (float) $row['speed_kph'];
        }
        if (isset($row['heading'])) {
            $ping['heading'] = (int) $row['heading'];
        }
        if (isset($row['accuracy_m'])) {
            $ping['accuracy_m'] = (float) $row['accuracy_m'];
        }
        if (isset($row['ignition'])) {
            $ping['ignition'] = (bool) $row['ignition'];
        }
        if (isset($row['odometer_m'])) {
            $ping['odometer_m'] = (int) $row['odometer_m'];
        }
        if (isset($row['device_id']) && $row['device_id'] !== '') {
            $ping['device_id'] = (string) $row['device_id'];
        }
        if (isset($row['fix_id']) && $row['fix_id'] !== '') {
            $ping['fix_id'] = (string) $row['fix_id'];
        }

        return $ping;
    }
}
