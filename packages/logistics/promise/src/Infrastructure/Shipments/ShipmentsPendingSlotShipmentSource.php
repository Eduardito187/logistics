<?php

declare(strict_types=1);

namespace Logistics\Promise\Infrastructure\Shipments;

use DateInterval;
use Illuminate\Contracts\Config\Repository as Config;
use Logistics\Promise\Domain\Contract\PendingSlotShipmentSource;
use Logistics\Shared\Application\Query\QueryBus;
use Logistics\Shared\Application\ReadModel\Page;
use Logistics\Shared\Domain\Clock\Clock;
use Logistics\Shipments\Application\Query\ListShipments\ListShipments;
use Logistics\Shipments\Application\ReadModel\ShipmentView;

/**
 * Implementación real del puerto de envíos sloteables: con la consulta pública de shipments pide los envíos
 * con fecha prometida en el horizonte (de hoy en adelante) y filtra en PHP los que todavía NO tienen
 * ventana (franja incompleta). No necesita un filtro nuevo en shipments: acota por rango de fecha y
 * descarta los que ya tienen ventana aquí.
 *
 * El cruce entre contextos es por el QueryBus y por primitivos; este adaptador no importa infraestructura
 * de shipments ni su dominio. Horizonte y default funcional vienen de Configuración (Promise).
 */
final readonly class ShipmentsPendingSlotShipmentSource implements PendingSlotShipmentSource
{
    /** Horizonte por defecto (días) si la config no lo define. */
    private const DEFAULT_HORIZON_DAYS = 30;

    public function __construct(
        private QueryBus $queryBus,
        private Clock $clock,
        private Config $config,
    ) {}

    public function pendingSlotShipments(int $limit): iterable
    {
        $from = $this->clock->now()->setTime(0, 0, 0);
        $to = $from->add(new DateInterval('P'.$this->horizonDays().'D'));

        /** @var list<ShipmentView>|Page<ShipmentView> $result */
        $result = $this->queryBus->ask(new ListShipments(
            deliveryDateFrom: $from->format('Y-m-d'),
            deliveryDateTo: $to->format('Y-m-d'),
        ));

        $views = $result instanceof Page ? $result->items : $result;

        $yielded = 0;
        foreach ($views as $view) {
            if ($yielded >= $limit) {
                break;
            }

            // Solo los que tienen fecha pero AÚN no ventana: franja incompleta = sloteable.
            if ($view->deliveryDate === null
                || ($view->deliveryWindowStart !== null && $view->deliveryWindowEnd !== null)) {
                continue;
            }

            $yielded++;

            yield [
                'shipment_id' => $view->id,
                'date' => $view->deliveryDate,
            ];
        }
    }

    private function horizonDays(): int
    {
        $value = $this->config->get('logistics-promise.auto_scheduling.sweep_horizon_days');
        $days = is_numeric($value) ? (int) $value : self::DEFAULT_HORIZON_DAYS;

        return $days > 0 ? $days : self::DEFAULT_HORIZON_DAYS;
    }
}
