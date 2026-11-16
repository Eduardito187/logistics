<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Service;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Logistics\Execution\Application\Contract\ShipmentRouteFactsReader;
use Logistics\Execution\Domain\Model\RouteStop;
use Logistics\Execution\Domain\Model\VehicleRoute;
use Logistics\Execution\Domain\ValueObject\RouteStopStatus;
use Throwable;

/**
 * Detecta las paradas que NO pueden ser de la ruta del día porque su envío ya estaba cerrado ANTES de ese
 * día: la parada figura entregada en una fecha anterior, o la entrega del envío se cerró (prueba de entrega
 * o cierre con faltantes) en una fecha anterior.
 *
 * Regla de la ruta del día: contiene las paradas del plan publicado de ese camión y día más las que se le
 * sumaron ese día; nunca la de un envío ya entregado. Sin este filtro, una entrega de días atrás contaba en
 * «parada N de M» y la app arrancaba la jornada en «parada 2» sin haber entregado nada.
 *
 * Conservador: una parada con actividad registrada el MISMO día de la ruta (llegada o cierre) no se toca.
 */
final readonly class StaleRouteStops
{
    public function __construct(
        private ShipmentRouteFactsReader $facts,
    ) {}

    /**
     * Envíos cuyas paradas sobran en la ruta por haberse cerrado antes del día de la ruta.
     *
     * @return list<string>
     */
    public function closedBeforeRouteDay(VehicleRoute $route): array
    {
        $routeDay = $route->date()->toString();
        $closedDays = $this->facts->closedDays($this->shipmentIdsOf($route));

        $stale = [];
        foreach ($route->stops() as $stop) {
            if (self::hasActivityOn($stop, $routeDay)) {
                continue;
            }

            if (self::isClosedBefore($stop, $routeDay, $closedDays)) {
                $stale[] = $stop->shipmentId();
            }
        }

        return $stale;
    }

    /**
     * Envíos cuyas paradas sobran en la ruta según la regla completa de la ruta del día, para el SANEO: una
     * parada sobra si su envío se cerró antes del día de la ruta, o si NO figura en el plan publicado de ese
     * camión/día y tampoco se sumó ese día (arrastrada de otra jornada). Conservador: nunca marca una parada
     * con actividad registrada ese mismo día (llegada o cierre).
     *
     * `$plannedShipmentIds` son los envíos del plan publicado del camión para esa fecha. Si es null (no hay
     * plan publicado que comparar) se omite el criterio del plan y solo actúa el de «cerrado antes»: así el
     * saneo nunca vacía una ruta cuyo plan ya no está, por las dudas.
     *
     * @param  list<string>|null $plannedShipmentIds
     * @return list<string>
     */
    public function staleIn(VehicleRoute $route, ?array $plannedShipmentIds): array
    {
        $routeDay = $route->date()->toString();
        $closedDays = $this->facts->closedDays($this->shipmentIdsOf($route));

        $stale = [];
        foreach ($route->stops() as $stop) {
            if (self::hasActivityOn($stop, $routeDay)) {
                continue;
            }

            $closedBefore = self::isClosedBefore($stop, $routeDay, $closedDays);
            $planMiss = $plannedShipmentIds !== null
                && !in_array($stop->shipmentId(), $plannedShipmentIds, true)
                && self::attachedOnOtherDay($stop, $routeDay);

            if ($closedBefore || $planMiss) {
                $stale[] = $stop->shipmentId();
            }
        }

        return $stale;
    }

    /**
     * Deja la ruta YA CARGADA sin esas paradas, para armar la vista. No persiste nada: es la defensa de la
     * lectura mientras existan filas viejas; la limpieza real la hace el comando de saneo.
     */
    public function hideFrom(VehicleRoute $route): VehicleRoute
    {
        $route->dropStops($this->closedBeforeRouteDay($route));

        return $route;
    }

    /**
     * ¿La parada registró llegada o cierre el día dado?
     */
    public static function hasActivityOn(RouteStop $stop, string $day): bool
    {
        return self::dayOf($stop->arrivedAt()) === $day || self::dayOf($stop->settledAt()) === $day;
    }

    /**
     * Día calendario (Y-m-d) en la zona de la operación de un instante o de una marca de tiempo en texto;
     * null si no hay o no se entiende. Un texto sin zona se interpreta en la zona de la operación.
     */
    public static function dayOf(mixed $at): ?string
    {
        $zone = new DateTimeZone(date_default_timezone_get());

        if (is_string($at) && trim($at) !== '') {
            try {
                $at = new DateTimeImmutable($at, $zone);
            } catch (Throwable) {
                $at = null;
            }
        }

        return $at instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($at)->setTimezone($zone)->format('Y-m-d')
            : null;
    }

    /** Día en que la parada quedó entregada, o null si no está entregada. */
    private static function deliveredDay(RouteStop $stop): ?string
    {
        return $stop->status() === RouteStopStatus::Completed ? self::dayOf($stop->settledAt()) : null;
    }

    /**
     * ¿El envío de la parada quedó ENTREGADO antes del día de la ruta? El día de cierre sale de los hechos del
     * envío; como respaldo, del día de entrega de la propia parada.
     *
     * @param array<string, string> $closedDays id de envío => día de cierre
     */
    private static function isClosedBefore(RouteStop $stop, string $routeDay, array $closedDays): bool
    {
        $closedDay = $closedDays[$stop->shipmentId()] ?? self::deliveredDay($stop);

        return $closedDay !== null && $closedDay < $routeDay;
    }

    /**
     * ¿La parada se sumó en un día distinto al de la ruta (o no quedó constancia de cuándo se sumó)? Una
     * parada del plan publicado no lleva ese dato (null); una sumada ese mismo día lo lleva con el día de la
     * ruta. Solo se usa junto con «no está en el plan» para decidir que la parada se arrastró de otra jornada.
     */
    private static function attachedOnOtherDay(RouteStop $stop, string $routeDay): bool
    {
        $attachedAt = $stop->attachedAt();

        return $attachedAt === null || self::dayOf($attachedAt) !== $routeDay;
    }

    /**
     * Ids de envío de todas las paradas de la ruta.
     *
     * @return list<string>
     */
    private function shipmentIdsOf(VehicleRoute $route): array
    {
        $ids = [];
        foreach ($route->stops() as $stop) {
            $ids[] = $stop->shipmentId();
        }

        return $ids;
    }
}
