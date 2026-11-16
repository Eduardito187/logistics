<?php

declare(strict_types=1);

namespace Logistics\Fleet\Infrastructure\Outbox;

use DateTimeImmutable;
use Illuminate\Database\ConnectionResolverInterface;
use Logistics\Fleet\Application\Command\AssembleCrew\AssembleCrew;
use Logistics\Fleet\Domain\Contract\CrewRoster;
use Logistics\Fleet\Domain\Exception\CrewConflict;
use Logistics\Fleet\Domain\Exception\FleetException;
use Logistics\Fleet\Domain\ValueObject\VehicleId;
use Logistics\Outbox\Application\Contract\MessageDispatcher;
use Logistics\Outbox\Domain\OutboxMessage;
use Logistics\Shared\Application\Command\CommandBus;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Reactor outbox: cuando PLANIFICACIÓN publica un plan (`planning.plan_published`), si el camión de ese plan
 * NO tiene tripulación armada para ese día, FLOTA arrastra al chofer más reciente de ese mismo camión y le
 * arma la tripulación del día. Así el flujo del operador «creo el plan → asigno el camión → publico» deja la
 * ruta visible para el chofer sin un paso manual aparte de armar la tripulación.
 *
 * Por qué hace falta: la ruta del chofer sale de la tripulación del día (`fleet_crews`: chofer↔camión↔fecha);
 * al publicar, EJECUCIÓN arma la ruta del camión pero NO le graba un chofer. Sin tripulación de ese día la
 * ruta queda huérfana y ningún chofer la ve.
 *
 * Reglas que respeta (no pisa asignaciones explícitas):
 *  - si el camión YA tiene tripulación ese día, no hace nada (no reasigna lo que el operador puso a mano);
 *  - si el camión nunca tuvo chofer, no inventa uno: queda para armado manual;
 *  - si el chofer más reciente del camión ya tiene OTRO camión ese día, no lo roba: no arrastra.
 * El armado pasa por {@see AssembleCrew}, que mantiene «un chofer = un camión por día» y es idempotente.
 *
 * Tolerante: nunca rompe la publicación. Un vehículo no apto o un conflicto de tripulación se registra y se
 * traga (el relay no reintenta el plan por esto); el resto del encadenamiento (ruta, estado) sigue su curso.
 */
final readonly class EnsureCrewOnPlanPublished implements MessageDispatcher
{
    public function __construct(
        private CommandBus $commandBus,
        private CrewRoster $roster,
        private ConnectionResolverInterface $connections,
        private LoggerInterface $logger,
    ) {}

    public function dispatch(OutboxMessage $message): void
    {
        if ($message->eventName() !== 'planning.plan_published') {
            return;
        }

        $payload = $message->payload();
        $vehicleId = $payload['vehicle_id'] ?? null;
        $planDate = $payload['plan_date'] ?? null;
        if (!is_string($vehicleId) || $vehicleId === '' || !is_string($planDate) || $planDate === '') {
            return;
        }

        try {
            $date = new DateTimeImmutable($planDate);

            // El camión ya tiene tripulación ese día (armada a mano o antes): no se toca.
            if ($this->roster->findForVehicleOn(VehicleId::fromString($vehicleId), $date) !== null) {
                return;
            }

            // Chofer (y ayudantes) más recientes de ese camión, para arrastrarlos a este día.
            $carry = $this->latestCrewOf($vehicleId, $planDate);
            if ($carry === null) {
                return; // el camión nunca tuvo chofer: queda para armado manual.
            }

            // No robar un chofer que ya tiene OTRO camión ese día.
            if ($this->driverHasAnotherVehicle($carry['driver_id'], $planDate, $vehicleId)) {
                return;
            }

            $this->commandBus->dispatch(new AssembleCrew(
                driverId: $carry['driver_id'],
                helperIds: $carry['helper_ids'],
                vehicleId: $vehicleId,
                assembledFor: $planDate,
            ));
        } catch (CrewConflict | FleetException $expected) {
            // Camión no apto o conflicto de tripulación: no rompe la publicación (se resuelve a mano).
            $this->logger->warning('No se pudo arrastrar la tripulación al publicar el plan', [
                'vehicle_id' => $vehicleId,
                'plan_date' => $planDate,
                'reason' => $expected->getMessage(),
            ]);
        } catch (Throwable $unexpected) {
            // Cualquier otra falla tampoco debe tumbar el encadenamiento de la publicación.
            $this->logger->warning('Fallo inesperado al arrastrar la tripulación al publicar el plan', [
                'vehicle_id' => $vehicleId,
                'plan_date' => $planDate,
                'error' => $unexpected->getMessage(),
            ]);
        }
    }

    /**
     * Chofer y ayudantes de la tripulación vigente más reciente de un camión ANTES de la fecha dada (la
     * planilla `fleet_crews` guarda la tripulación vigente por camión y día). Null si el camión no tiene
     * ninguna. El id de chofer debe tener forma de UUID (nunca se arrastra un dato corrupto).
     *
     * @return array{driver_id: string, helper_ids: list<string>}|null
     */
    private function latestCrewOf(string $vehicleId, string $planDate): ?array
    {
        $row = $this->connections->connection()->table('fleet_crews')
            ->where('vehicle_id', $vehicleId)
            ->where('assembled_for', '<', $planDate)
            ->whereNotNull('driver_id')
            ->orderByDesc('assembled_for')
            ->orderByDesc('created_at')
            ->first(['driver_id', 'helper_ids']);

        if ($row === null) {
            return null;
        }

        $driverId = is_string($row->driver_id) ? trim($row->driver_id) : '';
        if ($driverId === '') {
            return null;
        }

        return [
            'driver_id' => $driverId,
            'helper_ids' => $this->helperIds($row->helper_ids ?? null),
        ];
    }

    /**
     * ¿Ese chofer ya tiene una tripulación en OTRO camión ese día? (no se lo roba a una asignación existente).
     */
    private function driverHasAnotherVehicle(string $driverId, string $planDate, string $vehicleId): bool
    {
        return $this->connections->connection()->table('fleet_crews')
            ->where('driver_id', $driverId)
            ->where('assembled_for', $planDate)
            ->where('vehicle_id', '!=', $vehicleId)
            ->exists();
    }

    /**
     * Normaliza la columna de ayudantes (JSON o lista) a una lista de ids en texto.
     *
     * @return list<string>
     */
    private function helperIds(mixed $raw): array
    {
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $id) {
            if (is_string($id) && trim($id) !== '') {
                $ids[] = trim($id);
            }
        }

        return $ids;
    }
}
