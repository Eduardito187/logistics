<?php

declare(strict_types=1);

namespace Logistics\Execution\Application\Command\RecordStoreDrop;

use DateTimeImmutable;
use Logistics\Execution\Application\Contract\ShipmentFulfillmentReader;
use Logistics\Execution\Application\Contract\ShipmentUnitIdsReader;
use Logistics\Execution\Domain\Contract\DeliveryRepository;
use Logistics\Execution\Domain\Contract\VehicleRouteRepository;
use Logistics\Execution\Domain\Exception\ExecutionException;
use Logistics\Execution\Domain\Model\Delivery;
use Logistics\Execution\Domain\ValueObject\MissingUnitDeclaration;
use Logistics\Execution\Domain\ValueObject\MissingUnitReason;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Registra la ENTREGA EN TIENDA del chofer: marca en la entrega cuándo y en qué tienda quedó el pedido,
 * cierra la parada de su ruta y graba el hecho (que deja el pedido «listo para retirar» y avisa al cliente).
 *
 * La entrega NO se cierra como entregada: sigue abierta hasta que la tienda se la entrega al cliente en el
 * mostrador, con la prueba de entrega de siempre.
 *
 * Faltantes: el chofer puede declarar los productos que NO pudo dejar, con su motivo. Deben ser productos de
 * ESE pedido y no pueden ser todos (si no dejó ninguno no es una entrega en tienda: es una entrega fallida).
 *
 * Idempotente: repetirla no vuelve a grabar el hecho ni a avisar. Si la entrega nunca se abrió, se abre con
 * el camión de la ruta (igual que la prueba de entrega); sin entrega ni ruta no hay contexto y se rechaza.
 *
 * @implements CommandHandler<RecordStoreDrop>
 */
final class RecordStoreDropHandler implements CommandHandler
{
    public function __construct(
        private readonly DeliveryRepository $deliveries,
        private readonly VehicleRouteRepository $routes,
        private readonly ShipmentFulfillmentReader $fulfillment,
        private readonly RecordDomainEvents $recordEvents,
        private readonly ?ShipmentUnitIdsReader $units = null,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof RecordStoreDrop) {
            throw ExecutionException::because('RecordStoreDropHandler solo maneja RecordStoreDrop');
        }

        $occurredAt = new DateTimeImmutable($command->occurredAt);
        $missingUnits = $this->missingUnits($command);
        $route = $this->routes->findByShipmentId($command->shipmentId);

        $delivery = $this->deliveries->findByShipmentId($command->shipmentId);
        if ($delivery === null) {
            $vehicleId = $route?->vehicleId() ?? '';
            if (trim($vehicleId) === '') {
                throw ExecutionException::because("no hay entrega abierta para el envío {$command->shipmentId}");
            }

            $delivery = Delivery::open(
                $this->deliveries->nextIdentity(),
                $command->shipmentId,
                $vehicleId,
                $this->fulfillment->forShipmentId($command->shipmentId)->mode,
                $occurredAt,
            );
        }

        $delivery->dropAtStore($command->nodeId, $occurredAt, $command->photoPath, $missingUnits);

        $this->deliveries->save($delivery);
        $this->recordEvents->record($delivery->releaseEvents(), 'execution.delivery');

        // La parada del chofer queda resuelta: el camión ya no lleva este pedido.
        if ($route !== null) {
            $route->completeStop($command->shipmentId, $occurredAt);
            $this->routes->save($route);
            $this->recordEvents->record($route->releaseEvents(), 'execution.route');
        }
    }

    /**
     * Valida y arma los faltantes declarados: cada uno debe ser un producto del pedido (cuando el pedido
     * tiene sus productos registrados) y no pueden cubrir el pedido entero.
     *
     * @return list<MissingUnitDeclaration>
     */
    private function missingUnits(RecordStoreDrop $command): array
    {
        if ($command->missingUnits === []) {
            return [];
        }

        $declared = [];
        foreach ($command->missingUnits as $missing) {
            $reason = MissingUnitReason::tryFrom(strtolower(trim($missing['reason'])));
            if ($reason === null) {
                throw ExecutionException::because("motivo de faltante inválido: {$missing['reason']}");
            }

            $declaration = MissingUnitDeclaration::of($missing['unit_id'], $reason);
            $declared[$declaration->unitId] = $declaration;
        }

        $expected = $this->units?->forShipmentIds([$command->shipmentId])[$command->shipmentId] ?? [];
        if ($expected !== []) {
            $unknown = array_values(array_diff(array_keys($declared), $expected));
            if ($unknown !== []) {
                throw ExecutionException::because('el producto declarado como faltante no pertenece a este pedido: '.implode(', ', $unknown));
            }
            if (count($declared) >= count($expected)) {
                throw ExecutionException::because('no se dejó ningún producto en la tienda: registrá la entrega como fallida, no como entrega en tienda');
            }
        }

        return array_values($declared);
    }
}
