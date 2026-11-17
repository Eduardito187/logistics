<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordFailedDelivery;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\FailureReasonCatalog;
use Logistics\Execution\Application\Service\RouteDeliveryOpener;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\ValueObject\DeviceContext;
use Logistics\Execution\Domain\ValueObject\GeoPoint;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Carga la entrega del envío y registra el intento fallido con su motivo. Como el fallo también CIERRA
 * la parada de la ruta del chofer (atendida, aunque sin éxito), el mismo comando AVANZA la ruta a la
 * siguiente parada (si el camión tiene ruta armada).
 *
 * Idempotente: el agregado de la entrega descarta el escaneo si ya existe su idempotencyKey, y la ruta
 * ignora el cierre de una parada ya cerrada.
 *
 * @implements CommandHandler<RecordFailedDelivery>
 */
final class RecordFailedDeliveryHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly VehicleRouteRepository $routes,
        private readonly RecordDomainEvents $recordEvents,
        private readonly RouteDeliveryOpener $opener,
        private readonly FailureReasonCatalog $catalog,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RecordFailedDelivery) {
            throw ExecutionException::because('RecordFailedDeliveryHandler solo maneja RecordFailedDelivery');
        }

        $occurredAt = new DateTimeImmutable($command->occurredAt);

        // El motivo debe pertenecer al catálogo CONFIGURABLE y estar ACTIVO (los 5 de fábrica lo están por el
        // seeder). Un code desconocido o desactivado es una regla de negocio → 422 (no un 500 por enum).
        $reason = trim($command->reason);
        if (!$this->catalog->isActive($reason)) {
            throw ExecutionException::because("motivo de entrega fallida no válido o inactivo: {$reason}");
        }

        // Exigencias del motivo elegido: foto y/o nota obligatorias (configurables por el admin).
        $requirements = $this->catalog->requirements($reason);
        $note = $command->note !== null && trim($command->note) !== '' ? trim($command->note) : null;
        $photoPath = $command->photoPath !== null && trim($command->photoPath) !== '' ? trim($command->photoPath) : null;

        if ($requirements['requires_note'] && $note === null) {
            throw ExecutionException::because("el motivo {$reason} exige una nota");
        }
        // La foto viaja por una cola APARTE (offline-first): puede no estar subida al registrar la fallida. Por
        // eso la exigencia se da por cumplida con `photoPath` O con la señal `hasPhoto` de la app (que encoló la
        // foto). Nunca se bloquea por el archivo en sí, para no romper el registro sin señal.
        if ($requirements['requires_photo'] && $photoPath === null && !$command->hasPhoto) {
            throw ExecutionException::because("el motivo {$reason} exige una foto");
        }

        // Si el envío está en la ruta de un camión pero su entrega nunca se abrió (ruta armada desde el
        // plan publicado), se abre con ese camión para poder registrar el intento. Sin ruta, error claro.
        $delivery = $this->opener->forShipment($command->shipmentId, $occurredAt);
        if ($delivery === null) {
            throw ExecutionException::because("no hay entrega abierta para el envío {$command->shipmentId}");
        }

        $delivery->recordFailed(
            $reason,
            $note,
            $command->unitId,
            GeoPoint::of($command->lat, $command->lng),
            DeviceContext::of($command->deviceId, $command->userId),
            $occurredAt,
            $command->idempotencyKey,
        );

        $this->deliveries->save($delivery);

        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');

        // Avanza la ruta del chofer: la parada queda atendida (fallida) y pasa a la siguiente. Solo si el
        // camión tiene una ruta armada que incluye este envío.
        $route = $this->routes->findByShipmentId($command->shipmentId);
        if ($route !== null) {
            $route->failStop($command->shipmentId, $command->reason, $occurredAt);
            $this->routes->save($route);
            $this->recordEvents->record($route->releaseEvents(), 'execution.route');
        }
    }
}
