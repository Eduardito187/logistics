<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Application\Query\GetDriverHistory\GetDriverHistory;
use Logistics\Fleet\Application\Query\GetMyCrew\GetMyCrew;
use Logistics\Fleet\Application\Query\ListDocuments\ListDocuments;
use Logistics\Fleet\Application\Query\ListExpiringDocuments\ListExpiringDocuments;
use Logistics\Fleet\Application\ReadModel\DocumentView;
use Logistics\Fleet\Application\ReadModel\DriverHistoryView;
use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\Fleet\Application\ReadModel\MyCrewView;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Compositor de LECTURA del "hub 360" de un chofer: agrega su desempeño operativo (entregas, efectividad,
 * días y vehículos del periodo — execution vía fleet), su tripulación de hoy (vehículo + ayudantes),
 * documentos con vencimiento (licencia, etc.) y alertas de mal uso asociadas. Prefiere los QueryHandlers
 * del QueryBus (CQRS) y cae a lectura directa solo donde no hay query (alertas por chofer). Tolerante como
 * el resto del cockpit (cada fuente en su try/catch, degrada a null/[]/0). SOLO lectura; sin UUID visible.
 */
final class DriverHub
{
    /** Ventana del historial operativo por defecto: últimos 30 días. */
    private const WINDOW_DAYS = 30;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * Todo lo que la vista del hub del chofer necesita, en primitivos y tolerante.
     *
     * @return array{
     *   history: array{days_worked: int, total_stops: int, delivered: int, failed: int, rescheduled: int, effectiveness: int|null, vehicles: list<string>, series: array{labels: list<string>, delivered: list<int>}}|null,
     *   crew: array{vehicle_plate: string|null, helpers: list<string>}|null,
     *   compliance: array{expired: int, due_soon: int, upcoming: int},
     *   documents: list<array{type: string, reference: string|null, expires_on: string, state: string}>,
     *   alerts: list<array{type: string, severity: string, occurred_at: string}>,
     * }
     */
    public function overview(string $driverId): array
    {
        return [
            'history' => $this->history($driverId),
            'crew' => $this->crewToday($driverId),
            'compliance' => $this->compliance($driverId),
            'documents' => $this->documents($driverId),
            'alerts' => $this->alerts($driverId),
        ];
    }

    /**
     * Desempeño operativo de los últimos 30 días, o null si el contexto no está disponible.
     *
     * @return array{days_worked: int, total_stops: int, delivered: int, failed: int, rescheduled: int, effectiveness: int|null, vehicles: list<string>, series: array{labels: list<string>, delivered: list<int>}}|null
     */
    private function history(string $driverId): ?array
    {
        try {
            $view = $this->queryBus->ask(new GetDriverHistory(
                driverId: $driverId,
                from: now()->subDays(self::WINDOW_DAYS)->toDateString(),
                to: now()->toDateString(),
            ));
            if (!$view instanceof DriverHistoryView) {
                return null;
            }

            $closed = $view->delivered + $view->failed;
            $effectiveness = $closed > 0 ? (int) round($view->delivered / $closed * 100) : null;

            $labels = [];
            $delivered = [];
            foreach ($view->days as $day) {
                $date = isset($day['date']) ? (string) $day['date'] : '';
                $labels[] = $date !== '' ? Carbon::parse($date)->isoFormat('DD') : '';
                $delivered[] = isset($day['delivered']) ? (int) $day['delivered'] : 0;
            }

            return [
                'days_worked' => $view->daysWorked,
                'total_stops' => $view->totalStops,
                'delivered' => $view->delivered,
                'failed' => $view->failed,
                'rescheduled' => $view->rescheduled,
                'effectiveness' => $effectiveness,
                'vehicles' => array_values(array_filter(array_map(
                    static fn ($p): string => is_string($p) ? $p : '',
                    $view->vehicles,
                ))),
                'series' => ['labels' => $labels, 'delivered' => $delivered],
            ];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Tripulación de hoy: vehículo asignado y ayudantes. Null si no hay tripulación para hoy.
     *
     * @return array{vehicle_plate: string|null, helpers: list<string>}|null
     */
    private function crewToday(string $driverId): ?array
    {
        try {
            $view = $this->queryBus->ask(new GetMyCrew(driverId: $driverId, date: now()->toDateString()));
            if (!$view instanceof MyCrewView) {
                return null;
            }

            $plate = is_array($view->vehicle) && isset($view->vehicle['plate']) && is_string($view->vehicle['plate'])
                ? $view->vehicle['plate']
                : null;

            $helpers = [];
            foreach ($view->helpers as $helper) {
                if (is_array($helper) && isset($helper['name']) && is_string($helper['name']) && $helper['name'] !== '') {
                    $helpers[] = $helper['name'];
                }
            }

            return ['vehicle_plate' => $plate, 'helpers' => $helpers];
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Conteo de documentos del chofer por umbral de vencimiento (vencidos / ≤7d / ≤30d).
     *
     * @return array{expired: int, due_soon: int, upcoming: int}
     */
    private function compliance(string $driverId): array
    {
        $counts = ['expired' => 0, 'due_soon' => 0, 'upcoming' => 0];

        try {
            /** @var list<ExpiringDocumentView> $views */
            $views = $this->queryBus->ask(new ListExpiringDocuments(days: self::WINDOW_DAYS));
            $ref = new DateTimeImmutable('today');
            foreach ($views as $view) {
                if ($view->ownerType !== 'driver' || $view->ownerId !== $driverId) {
                    continue;
                }
                $threshold = $view->thresholdAt($ref);
                if ($threshold === ExpiringDocumentView::THRESHOLD_EXPIRED) {
                    $counts['expired']++;
                } elseif ($threshold === ExpiringDocumentView::THRESHOLD_DUE_SOON) {
                    $counts['due_soon']++;
                } elseif ($threshold === ExpiringDocumentView::THRESHOLD_UPCOMING) {
                    $counts['upcoming']++;
                }
            }
        } catch (Throwable) {
            // sin contexto de documentos: queda en cero.
        }

        return $counts;
    }

    /**
     * Documentos del chofer (tipo, referencia, vencimiento, estado), ordenados por vencimiento.
     *
     * @return list<array{type: string, reference: string|null, expires_on: string, state: string}>
     */
    private function documents(string $driverId): array
    {
        try {
            /** @var list<DocumentView> $views */
            $views = $this->queryBus->ask(new ListDocuments(ownerType: 'driver'));
            $out = [];
            foreach ($views as $view) {
                if ($view->ownerId !== $driverId) {
                    continue;
                }
                $out[] = [
                    'type' => $view->documentType,
                    'reference' => $view->reference,
                    'expires_on' => $view->expiresOn,
                    'state' => $this->expiryState($view->expiresOn),
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Alertas de mal uso asociadas al chofer (hueco: ListAlerts filtra por vehículo, no por chofer →
     * lectura directa de telemetry_alerts por driver_id).
     *
     * @return list<array{type: string, severity: string, occurred_at: string}>
     */
    private function alerts(string $driverId): array
    {
        try {
            return array_values(DB::table('telemetry_alerts')
                ->where('driver_id', $driverId)
                ->orderByDesc('occurred_at')
                ->limit(8)
                ->get(['type', 'severity', 'occurred_at'])
                ->map(fn ($r): array => [
                    'type' => (string) $r->type,
                    'severity' => (string) $r->severity,
                    'occurred_at' => $this->isoToShort(is_string($r->occurred_at) ? $r->occurred_at : null)
                        ?? (string) $r->occurred_at,
                ])
                ->all());
        } catch (Throwable) {
            return [];
        }
    }

    /** Estado de vencimiento de un documento por su fecha (vencido / por vencer ≤7d / próximo ≤30d / ok). */
    private function expiryState(string $expiresOn): string
    {
        $expiry = DateTimeImmutable::createFromFormat('!Y-m-d', $expiresOn);
        if ($expiry === false) {
            return 'ok';
        }

        $today = new DateTimeImmutable('today');
        if ($expiry < $today) {
            return 'expired';
        }

        $daysLeft = (int) $today->diff($expiry)->format('%a');
        if ($daysLeft <= 7) {
            return 'due_soon';
        }
        if ($daysLeft <= 30) {
            return 'upcoming';
        }

        return 'ok';
    }

    /** ISO 8601 → "19 oct 09:00" legible, o null si viene vacío/ilegible. */
    private function isoToShort(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }

        try {
            return Carbon::parse($iso)->isoFormat('DD MMM HH:mm');
        } catch (Throwable) {
            return null;
        }
    }
}
