<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\Command\QuotePromise;

use DateTimeImmutable;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Promise\Domain\Contract\AvailabilityProvider;
use Logistics\Promise\Domain\Contract\PromiseRepository;
use Logistics\Promise\Domain\Exception\PromiseException;
use Logistics\Promise\Domain\Model\DeliveryPromise;
use Logistics\Promise\Domain\ValueObject\DeliverySlot;
use Logistics\Promise\Domain\ValueObject\PromiseId;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;

/**
 * Cotiza una promesa: consulta al AvailabilityProvider las franjas con cupo y, si la franja pedida está
 * disponible, deja la promesa en estado Quoted y la persiste. Cotizar no reserva cupo ni graba eventos.
 *
 * El save del repositorio y el append al outbox corren dentro de la misma transacción, que abre el
 * decorador transaccional del CommandBus (Infrastructure); por eso acá no hay nada del framework.
 *
 * @implements CommandHandler<QuotePromise>
 */
final class QuotePromiseHandler implements CommandHandler
{
    private ?PromiseId $lastPromiseId = null;

    public function __construct(
        private readonly PromiseRepository $promises,
        private readonly AvailabilityProvider $availability,
        private readonly RecordDomainEvents $recordEvents,
    ) {}

    public function handle(Command $command): void
    {
        if (!$command instanceof QuotePromise) {
            throw PromiseException::because('QuotePromiseHandler solo maneja QuotePromise');
        }

        $date = $this->parseDate($command->slotDate);
        $requested = DeliverySlot::of($date, $command->timeSlot);

        // La franja pedida debe seguir disponible al cotizar (el cupo lo resuelve capacity vía el puerto).
        $available = $this->availability->availableSlots($command->zoneId, $date);
        if (!$this->contains($available, $requested)) {
            throw PromiseException::because('la franja pedida no tiene cupo disponible');
        }

        $promise = DeliveryPromise::quote(
            $this->promises->nextIdentity(),
            $command->shipmentRef,
            $command->zoneId,
            $requested,
        );

        $this->promises->save($promise);
        $this->lastPromiseId = $promise->id();

        // Cotizar no emite eventos hoy; igual se publica al outbox para seguir el mismo patrón que el
        // resto de los handlers (si no hay eventos, record() es un no-op).
        $this->recordEvents->record($promise->releaseEvents(), 'promise.promise');
    }

    /**
     * Id de la promesa cotizada tras el último handle().
     */
    public function lastPromiseId(): PromiseId
    {
        if ($this->lastPromiseId === null) {
            throw PromiseException::because('todavía no se cotizó ninguna promesa en este handler');
        }

        return $this->lastPromiseId;
    }

    private function parseDate(string $slotDate): DateTimeImmutable
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $slotDate);
        if ($date === false) {
            throw PromiseException::because("fecha de franja inválida (se esperaba Y-m-d): {$slotDate}");
        }

        return $date;
    }

    /**
     * @param list<DeliverySlot> $slots
     */
    private function contains(array $slots, DeliverySlot $needle): bool
    {
        foreach ($slots as $slot) {
            if ($slot->equals($needle)) {
                return true;
            }
        }

        return false;
    }
}
