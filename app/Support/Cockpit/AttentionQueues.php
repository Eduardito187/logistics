<?php

declare(strict_types=1);

namespace App\Support\Cockpit;

use App\Support\Cockpit\Concerns\ReadsCockpitAggregates;
use DateTimeImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Logistics\Fleet\Application\Query\ListExpiringDocuments\ListExpiringDocuments;
use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\Shared\Application\Query\QueryBus;
use Throwable;

/**
 * Compositor de LECTURA de las colas de "Necesita atención": bandejas de triage accionables que juntan,
 * en una sola pantalla, lo que está atascado o vencido y necesita que alguien actúe. Cada cola es una
 * consulta de agregación de SOLO lectura, tolerante (tabla/columna ausente → cola vacía, no rompe) y con
 * una lista corta con la referencia legible para enlazar al recurso que YA tiene la acción.
 *
 * No muta nada ni despacha comandos: es un tablero de señales. La acción se hace en el hub del envío /
 * pedido / vehículo / chofer al que enlaza cada fila. SOLO lectura; NUNCA expone un UUID como texto.
 */
final class AttentionQueues
{
    use ReadsCockpitAggregates;

    /** Máximo de filas por cola (el conteo es el total real; la lista es una muestra accionable). */
    private const SAMPLE = 8;

    /** Ventana de "por vencer" para documentos, en días. */
    private const DOCS_WINDOW_DAYS = 30;

    public function __construct(
        private readonly QueryBus $queryBus,
    ) {}

    /**
     * Las cuatro colas, cada una con su total y una muestra accionable.
     *
     * @return array{
     *   stuck_shipments: array{count: int, items: list<array{id: string, reference: string, customer: string, status: string}>},
     *   failed_deliveries: array{count: int, items: list<array{id: string, reference: string, reason: string}>},
     *   paid_without_shipment: array{count: int, items: list<array{id: string, customer: string, when: string}>},
     *   expiring_docs: array{count: int, items: list<array{owner_type: string, owner_id: string, owner_label: string, document_type: string, threshold: string, expires_on: string}>},
     * }
     */
    public function all(): array
    {
        return [
            'stuck_shipments' => $this->stuckShipments(),
            'failed_deliveries' => $this->failedDeliveries(),
            'paid_without_shipment' => $this->paidWithoutShipment(),
            'expiring_docs' => $this->expiringDocs(),
        ];
    }

    /**
     * Envíos atascados: activos (status no terminal) cuya fecha comprometida ya pasó.
     *
     * @return array{count: int, items: list<array{id: string, reference: string, customer: string, status: string}>}
     */
    private function stuckShipments(): array
    {
        try {
            $base = DB::table('shipments')
                ->whereNotIn('status', CockpitStates::SHIPMENT_TERMINAL)
                ->whereNotNull('delivery_date')
                ->whereDate('delivery_date', '<', now()->toDateString());

            $count = (clone $base)->count();
            $items = (clone $base)
                ->orderBy('delivery_date')
                ->limit(self::SAMPLE)
                ->get(['id', 'external_id', 'customer_name', 'status'])
                ->map(static fn ($r): array => [
                    'id' => (string) $r->id,
                    'reference' => (string) $r->external_id,
                    'customer' => (string) $r->customer_name,
                    'status' => (string) $r->status,
                ])
                ->all();

            return ['count' => $count, 'items' => array_values($items)];
        } catch (Throwable) {
            return ['count' => 0, 'items' => []];
        }
    }

    /**
     * Entregas fallidas cuyo envío sigue sin cerrarse (no entregado ni cancelado): necesitan reprogramarse.
     *
     * @return array{count: int, items: list<array{id: string, reference: string, reason: string}>}
     */
    private function failedDeliveries(): array
    {
        try {
            $base = DB::table('execution_deliveries as d')
                ->join('shipments as s', 's.id', '=', 'd.shipment_id')
                ->where('d.status', 'FAILED')
                ->whereNotIn('s.status', ['DELIVERED', 'CANCELLED']);

            $count = (clone $base)->count();
            $items = (clone $base)
                ->orderByDesc('d.updated_at')
                ->limit(self::SAMPLE)
                ->get(['s.id as shipment_id', 's.external_id', 'd.failure_reason'])
                ->map(static fn ($r): array => [
                    'id' => (string) $r->shipment_id,
                    'reference' => (string) $r->external_id,
                    'reason' => is_string($r->failure_reason) && $r->failure_reason !== ''
                        ? $r->failure_reason
                        : 'Sin motivo registrado',
                ])
                ->all();

            return ['count' => $count, 'items' => array_values($items)];
        } catch (Throwable) {
            return ['count' => 0, 'items' => []];
        }
    }

    /**
     * Pedidos pagados que todavía no generaron su envío (el puente order_paid → shipment no corrió o falló).
     *
     * @return array{count: int, items: list<array{id: string, customer: string, when: string}>}
     */
    private function paidWithoutShipment(): array
    {
        try {
            $base = DB::table('commerce_orders as o')
                ->where('o.status', 'paid')
                ->whereNotExists(static function ($q): void {
                    $q->select(DB::raw(1))
                        ->from('shipments as s')
                        ->whereColumn('s.order_reference', 'o.id');
                });

            $count = (clone $base)->count();
            $items = (clone $base)
                ->orderByDesc('o.created_at')
                ->limit(self::SAMPLE)
                ->get(['o.id', DB::raw("o.ship_to->>'customer_name' as customer_name"), 'o.created_at'])
                ->map(function ($r): array {
                    return [
                        'id' => (string) $r->id,
                        'customer' => is_string($r->customer_name) && $r->customer_name !== ''
                            ? $r->customer_name
                            : 'Cliente',
                        'when' => $this->shortDate(is_string($r->created_at) ? $r->created_at : null),
                    ];
                })
                ->all();

            return ['count' => $count, 'items' => array_values($items)];
        } catch (Throwable) {
            return ['count' => 0, 'items' => []];
        }
    }

    /**
     * Documentos de flota vencidos o por vencer (≤30d): licencia, SOAT, inspección técnica, etc. El
     * ReadModel ya trae la etiqueta legible del dueño (placa / nombre), así que no se expone ningún UUID.
     *
     * @return array{count: int, items: list<array{owner_type: string, owner_id: string, owner_label: string, document_type: string, threshold: string, expires_on: string}>}
     */
    private function expiringDocs(): array
    {
        try {
            /** @var list<ExpiringDocumentView> $views */
            $views = $this->queryBus->ask(new ListExpiringDocuments(days: self::DOCS_WINDOW_DAYS));
            $ref = new DateTimeImmutable('today');

            $items = [];
            foreach ($views as $view) {
                $items[] = [
                    'owner_type' => $view->ownerType,
                    'owner_id' => $view->ownerId,
                    'owner_label' => $view->ownerLabel,
                    'document_type' => $view->documentType,
                    'threshold' => $view->thresholdAt($ref),
                    'expires_on' => $view->expiresOn,
                ];
            }

            // Primero lo vencido, luego por vencer, luego próximo; dentro, por fecha de vencimiento.
            $order = [
                ExpiringDocumentView::THRESHOLD_EXPIRED => 0,
                ExpiringDocumentView::THRESHOLD_DUE_SOON => 1,
                ExpiringDocumentView::THRESHOLD_UPCOMING => 2,
            ];
            usort($items, static function (array $a, array $b) use ($order): int {
                return [$order[$a['threshold']] ?? 9, $a['expires_on']] <=> [$order[$b['threshold']] ?? 9, $b['expires_on']];
            });

            return ['count' => count($items), 'items' => array_slice($items, 0, self::SAMPLE)];
        } catch (Throwable) {
            return ['count' => 0, 'items' => []];
        }
    }

    /** Fecha corta "05 nov" a partir de un timestamp, tolerante. */
    private function shortDate(?string $iso): string
    {
        if ($iso === null || trim($iso) === '') {
            return '';
        }

        try {
            return Carbon::parse($iso)->isoFormat('DD MMM');
        } catch (Throwable) {
            return '';
        }
    }
}
