<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Presentation\Filament\Pages;

use BackedEnum;
use DateTimeImmutable;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Logistics\Shared\Application\Command\CommandBus;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Telemetry\Application\Command\AcknowledgeAlert\AcknowledgeAlert;
use Logistics\Telemetry\Application\Query\GetLiveFleetPositions\GetLiveFleetPositions;
use Logistics\Telemetry\Application\Query\GetVehicleTrack\GetVehicleTrack;
use Logistics\Telemetry\Application\Query\ListAlerts\ListAlerts;
use Logistics\Telemetry\Application\ReadModel\AlertView;
use Logistics\Telemetry\Application\ReadModel\PingView;
use Logistics\Telemetry\Application\ReadModel\VehicleLiveView;
use Logistics\Telemetry\Domain\ValueObject\AlertSeverity;
use Logistics\Telemetry\Domain\ValueObject\AlertType;
use Logistics\Telemetry\Presentation\Filament\Support\VehiclePlates;

/**
 * Cockpit de telemetría "operaciones-primero": a la izquierda el mapa en vivo de toda la flota sobre
 * OpenStreetMap (Leaflet, sin API key) y a la derecha un panel de EXCEPCIONES accionables (vehículos
 * inactivos/ralentí, alertas de telemetría sin reconocer y posibles mal usos). Cada alerta trae una
 * acción inline "Acknowledge" que despacha el Command de reconocimiento; clic en cualquier fila hace
 * drill-down centrando el mapa en la unidad y dibujando su recorrido del día.
 *
 * Nota de arquitectura: TODOS los datos de lectura salen del lado CQRS (QueryBus → GetLiveFleetPositions
 * / GetVehicleTrack / ListAlerts → ReadModels), nunca de Eloquent ni del dominio directo; las acciones de
 * negocio (acknowledge) despachan al CommandBus. El refresco es por polling desde el navegador, que vuelve
 * a llamar a los métodos Livewire; no hay I/O externo síncrono en el request. Las lecturas toleran tablas
 * ausentes (devuelven vacío en vez de romper). Ningún UUID visible: la placa se resuelve con VehiclePlates
 * y los campos vacíos caen a '—'. Texto visible en inglés (regla 5); comentarios en español (regla 6).
 */
final class LiveFleetMap extends Page
{
    protected static string | BackedEnum | null $navigationIcon = 'heroicon-o-map';

    protected string $view = 'logistics-telemetry::filament.pages.live-fleet-map';

    public static function getNavigationGroup(): string
    {
        return __('Fleet');
    }

    public static function getNavigationSort(): int
    {
        return 5;
    }

    public static function getNavigationLabel(): string
    {
        return __('Live fleet map');
    }

    public function getTitle(): string
    {
        return __('Live fleet map');
    }

    /**
     * Posición en vivo de cada vehículo como primitivos, lista para el mapa. Se expone como método
     * público para que el polling del navegador (`$wire.positions()`) la vuelva a pedir sin recargar.
     * Tolera tablas ausentes: si la lectura falla, devuelve una flota vacía en vez de romper la página.
     *
     * @return list<array{vehicle_id: string, lat: float, lng: float, speed_kph: float|null, heading: int|null, ignition: bool|null, moving: bool, route_id: string|null, recorded_at: string}>
     */
    public function positions(): array
    {
        return array_map(
            static fn (VehicleLiveView $view): array => $view->toArray(),
            $this->livePositions(),
        );
    }

    /**
     * Panel de excepciones accionables: alertas de telemetría sin reconocer (posibles mal usos) y
     * vehículos inactivos (detenidos o en ralentí). Se expone como método público para que el polling
     * del navegador (`$wire.exceptions()`) lo refresque. Todo en primitivos, placas ya resueltas.
     *
     * @return array{alerts: list<array{id: string, vehicle_id: string, vehicle: string, type: string, severity: string, severity_label: string, severity_color: string, age_minutes: int, lat: float|null, lng: float|null}>, idle: list<array{vehicle_id: string, vehicle: string, state: string, idling: bool, age_minutes: int, lat: float, lng: float, speed_kph: float|null}>}
     */
    public function exceptions(): array
    {
        return [
            'alerts' => $this->unacknowledgedAlerts(),
            'idle' => $this->idleVehicles(),
        ];
    }

    /**
     * Reconoce (acknowledge) una alerta de telemetría despachando el Command al CommandBus CQRS (nunca se
     * escribe el record de lectura directo). Actor = email del usuario del admin. Devuelve las excepciones
     * frescas para que el panel se repinte sin recargar. Mismo patrón que TelemetryAlertResource.
     *
     * @return array{alerts: list<array{id: string, vehicle_id: string, vehicle: string, type: string, severity: string, severity_label: string, severity_color: string, age_minutes: int, lat: float|null, lng: float|null}>, idle: list<array{vehicle_id: string, vehicle: string, state: string, idling: bool, age_minutes: int, lat: float, lng: float, speed_kph: float|null}>}
     */
    public function acknowledge(string $alertId): array
    {
        app(CommandBus::class)->dispatch(new AcknowledgeAlert(
            alertId: $alertId,
            by: (string) auth()->user()?->email,
        ));

        Notification::make()
            ->title(__('Alert acknowledged'))
            ->success()
            ->send();

        return $this->exceptions();
    }

    /**
     * Recorrido (track) del vehículo durante el día de hoy, para dibujarlo al hacer clic en su marcador
     * o en una fila del panel de excepciones. Se pide al lado de lectura entre el inicio y el fin del día.
     *
     * @return list<array{lat: float, lng: float, speed_kph: float|null, heading: int|null, recorded_at: string}>
     */
    public function vehicleTrack(string $vehicleId): array
    {
        $from = (new DateTimeImmutable('today'))->format(DATE_ATOM);
        $to = (new DateTimeImmutable('tomorrow'))->format(DATE_ATOM);

        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);

        try {
            /** @var list<PingView> $pings */
            $pings = $queryBus->ask(new GetVehicleTrack($vehicleId, $from, $to));
        } catch (\Throwable) {
            return [];
        }

        return array_map(static fn (PingView $ping): array => $ping->toArray(), $pings);
    }

    /**
     * Posiciones en vivo de la flota desde el lado de lectura CQRS; lista vacía si la tabla no existe aún.
     *
     * @return list<VehicleLiveView>
     */
    private function livePositions(): array
    {
        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);

        try {
            /** @var list<VehicleLiveView> $views */
            $views = $queryBus->ask(new GetLiveFleetPositions);

            return $views;
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Alertas de telemetría sin reconocer, ordenadas por severidad (crítico primero) y antigüedad. Son
     * las excepciones accionables: cada una lleva su acción inline "Acknowledge". Placas ya resueltas.
     *
     * @return list<array{id: string, vehicle_id: string, vehicle: string, type: string, severity: string, severity_label: string, severity_color: string, age_minutes: int, lat: float|null, lng: float|null}>
     */
    private function unacknowledgedAlerts(): array
    {
        /** @var QueryBus $queryBus */
        $queryBus = app(QueryBus::class);

        try {
            /** @var list<AlertView> $views */
            $views = $queryBus->ask(new ListAlerts(acknowledged: false));
        } catch (\Throwable) {
            return [];
        }

        $rows = array_map(function (AlertView $view): array {
            $meta = $this->severityMeta($view->severity);

            return [
                'id' => $view->id,
                // vehicle_id solo para el drill-down del track en JS; nunca se renderiza (no es UUID visible).
                'vehicle_id' => $view->vehicleId,
                'vehicle' => VehiclePlates::label($view->vehicleId) ?? '—',
                'type' => __($this->typeLabel($view->type)),
                'severity' => $view->severity,
                'severity_label' => __($meta['label']),
                'severity_color' => $meta['color'],
                'severity_rank' => $meta['rank'],
                'age_minutes' => $this->ageMinutes($view->occurredAt),
                'lat' => $view->lat,
                'lng' => $view->lng,
            ];
        }, $views);

        // Lo crítico y lo más viejo primero: ordena por rango de severidad y, a igualdad, por antigüedad.
        usort($rows, static function (array $a, array $b): int {
            return [$a['severity_rank'], -$a['age_minutes']] <=> [$b['severity_rank'], -$b['age_minutes']];
        });

        // El rango de severidad es solo para ordenar; no se expone al cliente.
        return array_map(static function (array $row): array {
            unset($row['severity_rank']);

            return $row;
        }, $rows);
    }

    /**
     * Vehículos inactivos a partir de la posición en vivo: detenidos (motor apagado) o en ralentí
     * (motor encendido sin movimiento, posible mal uso). El ralentí va primero por ser el más sospechoso.
     *
     * @return list<array{vehicle_id: string, vehicle: string, state: string, idling: bool, age_minutes: int, lat: float, lng: float, speed_kph: float|null}>
     */
    private function idleVehicles(): array
    {
        $rows = [];

        foreach ($this->livePositions() as $view) {
            $position = $view->toArray();

            if ($position['moving'] === true) {
                continue;
            }

            // Motor encendido y sin movimiento = ralentí (posible mal uso / descanso no autorizado).
            $idling = $position['ignition'] === true;

            $rows[] = [
                'vehicle_id' => $position['vehicle_id'],
                'vehicle' => VehiclePlates::label($position['vehicle_id']) ?? '—',
                'state' => $idling ? 'idling' : 'stopped',
                'idling' => $idling,
                'age_minutes' => $this->ageMinutes($position['recorded_at']),
                'lat' => $position['lat'],
                'lng' => $position['lng'],
                'speed_kph' => $position['speed_kph'],
            ];
        }

        // Ralentí primero (más sospechoso); a igualdad, el que lleva más tiempo quieto.
        usort($rows, static function (array $a, array $b): int {
            return [$a['idling'] ? 0 : 1, -$a['age_minutes']] <=> [$b['idling'] ? 0 : 1, -$b['age_minutes']];
        });

        return $rows;
    }

    /**
     * Etiqueta humana (inglés) del tipo de alerta; el valor crudo si el enum no lo reconoce.
     */
    private function typeLabel(string $type): string
    {
        return AlertType::tryFrom($type)?->label() ?? $type;
    }

    /**
     * Metadatos de severidad para el panel: color del badge, rango de orden (menor = más urgente) y
     * etiqueta humana en inglés.
     *
     * @return array{color: string, rank: int, label: string}
     */
    private function severityMeta(string $severity): array
    {
        return match ($severity) {
            AlertSeverity::Critical->value => ['color' => 'danger', 'rank' => 0, 'label' => 'Critical'],
            AlertSeverity::Warning->value => ['color' => 'warning', 'rank' => 1, 'label' => 'Warning'],
            default => ['color' => 'info', 'rank' => 2, 'label' => 'Info'],
        };
    }

    /**
     * Antigüedad en minutos de un instante ISO 8601 respecto de ahora; 0 si es futuro o no parseable.
     */
    private function ageMinutes(string $iso): int
    {
        try {
            $then = new DateTimeImmutable($iso);
        } catch (\Exception) {
            return 0;
        }

        $seconds = (new DateTimeImmutable('now'))->getTimestamp() - $then->getTimestamp();

        return $seconds > 0 ? intdiv($seconds, 60) : 0;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        return [
            // Datos iniciales para el primer pintado; el polling del navegador los refresca luego.
            'positions' => $this->positions(),
            'exceptions' => $this->exceptions(),
            // Mapa id→placa para resolver el popup del marcador sin exponer el UUID (regla: nada de UUID visible).
            'plates' => VehiclePlates::options(),
        ];
    }
}
