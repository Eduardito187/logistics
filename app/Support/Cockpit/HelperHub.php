<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Application\Query\ListDocuments\ListDocuments;
use Logistics\Fleet\Application\Query\ListExpiringDocuments\ListExpiringDocuments;
use Logistics\Fleet\Application\ReadModel\DocumentView;
use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Compositor de LECTURA del "hub 360" de un AYUDANTE: agrega sus documentos con vencimiento (owner_type
 * 'helper' en `fleet_documents`) y las tripulaciones en las que participa (`fleet_crews.helper_ids`). Los
 * datos personales (CI, teléfonos, dirección, referencias) los toma la vista del registro Eloquent — igual
 * que el hub del chofer. Prefiere los QueryHandlers del QueryBus (CQRS) y cae a lectura directa para las
 * tripulaciones. Tolerante como el resto del cockpit (cada fuente en su try/catch). SOLO lectura; sin UUID.
 */
final class HelperHub
{
    /** Ventana de vencimiento de documentos. */
    private const WINDOW_DAYS = 30;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * Todo lo que la vista del hub del ayudante necesita, en primitivos y tolerante.
     *
     * @return array{
     *   compliance: array{expired: int, due_soon: int, upcoming: int},
     *   documents: list<array{type: string, reference: string|null, expires_on: string, state: string}>,
     *   crews: list<array{vehicle_plate: string, date: ?string}>,
     * }
     */
    public function overview(string $helperId): array
    {
        return [
            'compliance' => $this->compliance($helperId),
            'documents' => $this->documents($helperId),
            'crews' => $this->crews($helperId),
        ];
    }

    /**
     * Conteo de documentos del ayudante por umbral de vencimiento (vencidos / ≤7d / ≤30d).
     *
     * @return array{expired: int, due_soon: int, upcoming: int}
     */
    private function compliance(string $helperId): array
    {
        $counts = ['expired' => 0, 'due_soon' => 0, 'upcoming' => 0];

        try {
            /** @var list<ExpiringDocumentView> $views */
            $views = $this->queryBus->ask(new ListExpiringDocuments(days: self::WINDOW_DAYS));
            $ref = new DateTimeImmutable('today');
            foreach ($views as $view) {
                if ($view->ownerType !== 'helper' || $view->ownerId !== $helperId) {
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
     * Documentos del ayudante (tipo, referencia, vencimiento, estado).
     *
     * @return list<array{type: string, reference: string|null, expires_on: string, state: string}>
     */
    private function documents(string $helperId): array
    {
        try {
            /** @var list<DocumentView> $views */
            $views = $this->queryBus->ask(new ListDocuments(ownerType: 'helper'));
            $out = [];
            foreach ($views as $view) {
                if ($view->ownerId !== $helperId) {
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
     * Tripulaciones en las que participa el ayudante (su id está en `helper_ids` jsonb), con la placa del
     * vehículo resuelta y la fecha. Más recientes primero.
     *
     * @return list<array{vehicle_plate: string, date: ?string}>
     */
    private function crews(string $helperId): array
    {
        try {
            $rows = DB::table('fleet_crews')
                ->whereJsonContains('helper_ids', $helperId)
                ->orderByDesc('assembled_for')
                ->limit(12)
                ->get(['vehicle_id', 'assembled_for']);

            $plates = $this->vehiclePlates(array_values(array_unique(array_filter(
                $rows->map(static fn ($r): string => is_string($r->vehicle_id) ? $r->vehicle_id : '')->all(),
                static fn (string $id): bool => $id !== '',
            ))));

            $out = [];
            foreach ($rows as $r) {
                $plate = $plates[(string) $r->vehicle_id] ?? null;
                if ($plate === null) {
                    continue;
                }
                $out[] = [
                    'vehicle_plate' => $plate,
                    'date' => is_string($r->assembled_for) && $r->assembled_for !== ''
                        ? Carbon::parse($r->assembled_for)->isoFormat('DD MMM YYYY')
                        : null,
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Placas de vehículos por id, en lote (para no mostrar UUIDs).
     *
     * @param  list<string>          $ids
     * @return array<string, string>
     */
    private function vehiclePlates(array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        try {
            /** @var array<string, string> $plates */
            $plates = DB::table('fleet_vehicles')->whereIn('id', $ids)->pluck('plate', 'id')->all();

            return $plates;
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
}
