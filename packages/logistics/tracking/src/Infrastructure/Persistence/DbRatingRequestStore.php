<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Persistence;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Logistics\Tracking\Application\Contract\RatingRequestStore;
use Logistics\Tracking\Application\ReadModel\DeliveredAwaitingRatingView;
use Logistics\Tracking\Domain\ValueObject\VisibleStatus;

/**
 * Store SQL de las invitaciones a calificar sobre `tracking_shipments`. Escribe solo columnas que el
 * agregado no mapea (`customer_email`, `rating_requested_at`), así que el upsert del repositorio nunca las
 * pisa. La lista de candidatos usa el índice parcial `tracking_shipments_awaiting_rating_partial_idx`; el
 * nombre del cliente se toma del lado lectura de `shipments` (LEFT JOIN, solo lectura, tolerante a nulos).
 */
final readonly class DbRatingRequestStore implements RatingRequestStore
{
    private const string TABLE = 'tracking_shipments';

    public function __construct(
        private ConnectionResolverInterface $connections,
    ) {}

    public function attachCustomerEmail(string $shipmentId, string $email): void
    {
        $this->db()->table(self::TABLE)
            ->where('shipment_id', $shipmentId)
            ->whereNull('customer_email')
            ->update(['customer_email' => $email]);
    }

    public function awaitingRating(DateTimeImmutable $since, DateTimeImmutable $deliveredBefore, int $limit): array
    {
        // `delivered_at` lo escribe Eloquent en hora de pared local (sin offset): se comparan los cortes en
        // la misma zona de la app para no desalinearse con esa escritura (mismo criterio que la lectura
        // incremental de telemetría). Bindear con offset (ATOM) caería en otro marco y dejaría pasar
        // entregas demasiado recientes.
        $tz = new DateTimeZone(date_default_timezone_get());

        $rows = $this->db()->table(self::TABLE.' as t')
            ->leftJoin('shipments as s', 's.id', '=', 't.shipment_id')
            ->where('t.status', VisibleStatus::Delivered->value)
            ->whereNotNull('t.shipment_id')
            ->whereNotNull('t.customer_email')
            ->whereNull('t.rating_requested_at')
            ->where('t.delivered_at', '>=', $since->setTimezone($tz))
            ->where('t.delivered_at', '<=', $deliveredBefore->setTimezone($tz))
            ->whereNotExists(static function (Builder $query): void {
                $query->selectRaw('1')
                    ->from('tracking_satisfaction_surveys as r')
                    ->whereColumn('r.shipment_id', 't.shipment_id');
            })
            ->orderBy('t.delivered_at')
            ->orderBy('t.id')
            ->limit($limit)
            ->get([
                't.shipment_id',
                't.order_reference',
                't.token',
                't.customer_email',
                't.delivered_at',
                's.customer_name',
                's.order_reference as shipment_order_reference',
                's.external_id',
            ]);

        $views = [];
        foreach ($rows as $row) {
            $views[] = new DeliveredAwaitingRatingView(
                (string) $row->shipment_id,
                self::str($row->order_reference ?? null)
                    ?? self::str($row->shipment_order_reference ?? null)
                    ?? self::str($row->external_id ?? null),
                self::str($row->customer_name ?? null),
                (string) $row->customer_email,
                Carbon::parse((string) $row->delivered_at)->toIso8601String(),
                (string) $row->token,
            );
        }

        return $views;
    }

    public function markRequested(string $shipmentId, DateTimeImmutable $requestedAt): bool
    {
        $affected = $this->db()->table(self::TABLE)
            ->where('shipment_id', $shipmentId)
            ->whereNull('rating_requested_at')
            ->update(['rating_requested_at' => $requestedAt->format(DateTimeImmutable::ATOM)]);

        return $affected > 0;
    }

    private function db(): ConnectionInterface
    {
        return $this->connections->connection();
    }

    /** Normaliza a string no vacío o null. */
    private static function str(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
