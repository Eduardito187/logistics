<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Command\AlertExpiringDocuments;

use DateTimeImmutable;
use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\Fleet\Domain\Contract\DocumentExpiryPolicy;
use Logistics\Fleet\Domain\Contract\DocumentRepository;
use Logistics\Fleet\Domain\Contract\DriverRepository;
use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Fleet\Domain\Event\FleetDocumentsExpiryAlerted;
use Logistics\Outbox\Application\RecordDomainEvents;
use Logistics\Shared\Application\Command\Command;
use Logistics\Shared\Application\Command\CommandHandler;
use Logistics\Shared\Domain\Clock\Clock;

/**
 * Barrido programado de documentos por vencer / vencidos (P1-FLT-04).
 *
 * Recorre las cuatro fuentes de vencimiento del contexto (vehículos, choferes, ayudantes y documentos
 * de flota con archivo) a la fecha de referencia y, si encuentra al menos uno dentro de la ventana de
 * alerta, emite un único evento de resumen al outbox (FleetDocumentsExpiryAlerted). El relay lo entrega
 * a notificaciones, que avisa al supervisor; no hay I/O externo síncrono acá (principio 4).
 *
 * Deja el resumen de la última corrida accesible para el comando de consola (totales + si emitió).
 *
 * @implements CommandHandler<AlertExpiringDocuments>
 */
final class AlertExpiringDocumentsHandler implements CommandHandler
{
    private int $lastExpired = 0;

    private int $lastExpiring = 0;

    private bool $lastEmitted = false;

    public function __construct(
        private readonly VehicleRepository $vehicles,
        private readonly DriverRepository $drivers,
        private readonly HelperRepository $helpers,
        private readonly DocumentRepository $documents,
        private readonly DocumentExpiryPolicy $policy,
        private readonly RecordDomainEvents $recordEvents,
        private readonly Clock $clock,
    ) {}

    public function handle(Command $command): void
    {
        assert($command instanceof AlertExpiringDocuments);

        $ref = $command->referenceDate !== null
            ? new DateTimeImmutable($command->referenceDate)
            : $this->clock->now();

        $days = $command->days ?? $this->policy->alertWindowDays();
        if ($days < 0) {
            $days = 0;
        }

        $views = $this->scan($days, $ref);

        $this->lastExpired = count(array_filter($views, static fn (ExpiringDocumentView $v): bool => $v->expired));
        $this->lastExpiring = count($views) - $this->lastExpired;
        $this->lastEmitted = false;

        // Sin documentos en la ventana no hay nada que alertar: no se ensucia el outbox.
        if ($views === []) {
            return;
        }

        $documents = array_map(static fn (ExpiringDocumentView $v): array => $v->toArray(), $views);

        $event = new FleetDocumentsExpiryAlerted(
            'fleet-document-expiry-'.$ref->format('Y-m-d'),
            $ref->format('Y-m-d'),
            $days,
            $this->lastExpired,
            $this->lastExpiring,
            array_values($documents),
        );

        $this->recordEvents->record([$event], 'fleet.document_expiry');
        $this->lastEmitted = true;
    }

    /**
     * Reúne, de las cuatro fuentes, los documentos que vencen dentro de la ventana (incluidos los ya
     * vencidos). Mismo criterio que ListExpiringDocuments, para que el tablero y la alerta coincidan.
     *
     * @return list<ExpiringDocumentView>
     */
    private function scan(int $days, DateTimeImmutable $ref): array
    {
        $results = [];

        foreach ($this->vehicles->all() as $vehicle) {
            foreach ($vehicle->documentsExpiringWithin($days, $ref) as $document) {
                $results[] = ExpiringDocumentView::forVehicle(
                    $vehicle->id()->toString(),
                    $vehicle->plate()->value,
                    $document->type,
                    $document->expiresOn->format('Y-m-d'),
                    $document->isExpiredAt($ref),
                );
            }
        }

        foreach ($this->drivers->all() as $driver) {
            $license = $driver->licenseExpiry();
            if ($license->expiresWithin($days, $ref)) {
                $results[] = ExpiringDocumentView::forDriver(
                    $driver->id()->toString(),
                    $driver->name(),
                    $license->type,
                    $license->expiresOn->format('Y-m-d'),
                    $license->isExpiredAt($ref),
                );
            }
        }

        foreach ($this->helpers->all() as $helper) {
            foreach ($helper->documentsExpiringWithin($days, $ref) as $document) {
                $results[] = ExpiringDocumentView::forHelper(
                    $helper->id()->toString(),
                    $helper->name(),
                    $document->type,
                    $document->expiresOn->format('Y-m-d'),
                    $document->isExpiredAt($ref),
                );
            }
        }

        foreach ($this->documents->all() as $document) {
            if ($document->expiresWithin($days, $ref)) {
                $results[] = ExpiringDocumentView::forDocument(
                    $document->ownerType()->value,
                    $document->ownerId(),
                    $document->ownerId(),
                    $document->document()->type,
                    $document->document()->expiresOn->format('Y-m-d'),
                    $document->isExpiredAt($ref),
                );
            }
        }

        return $results;
    }

    /**
     * Cantidad de documentos ya vencidos detectados en la última corrida.
     */
    public function lastExpired(): int
    {
        return $this->lastExpired;
    }

    /**
     * Cantidad de documentos por vencer (aún vigentes) detectados en la última corrida.
     */
    public function lastExpiring(): int
    {
        return $this->lastExpiring;
    }

    /**
     * Total de documentos dentro de la ventana en la última corrida.
     */
    public function lastTotal(): int
    {
        return $this->lastExpired + $this->lastExpiring;
    }

    /**
     * ¿La última corrida emitió el evento de alerta al outbox?
     */
    public function lastEmitted(): bool
    {
        return $this->lastEmitted;
    }
}
