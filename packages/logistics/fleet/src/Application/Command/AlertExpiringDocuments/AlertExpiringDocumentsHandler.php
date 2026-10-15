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

    private int $lastDueSoon = 0;

    private int $lastUpcoming = 0;

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

        // Clasifica cada documento por umbral (vencido / ≤7 días / ≤30 días) a la fecha de referencia.
        $documents = array_map(
            static fn (ExpiringDocumentView $v): array => $v->toArray() + ['threshold' => $v->thresholdAt($ref)],
            $views,
        );

        $this->lastExpired = $this->countThreshold($documents, ExpiringDocumentView::THRESHOLD_EXPIRED);
        $this->lastDueSoon = $this->countThreshold($documents, ExpiringDocumentView::THRESHOLD_DUE_SOON);
        $this->lastUpcoming = $this->countThreshold($documents, ExpiringDocumentView::THRESHOLD_UPCOMING);
        $this->lastExpiring = $this->lastDueSoon + $this->lastUpcoming;
        $this->lastEmitted = false;

        // Sin documentos en la ventana no hay nada que alertar: no se ensucia el outbox.
        if ($documents === []) {
            return;
        }

        $event = new FleetDocumentsExpiryAlerted(
            'fleet-document-expiry-'.$ref->format('Y-m-d'),
            $ref->format('Y-m-d'),
            $days,
            $this->lastExpired,
            $this->lastExpiring,
            array_values($documents),
            $this->lastDueSoon,
            $this->lastUpcoming,
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
        $vehicles = $this->vehicles->all();
        $drivers = $this->drivers->all();
        $helpers = $this->helpers->all();

        // Mapas id → etiqueta legible (placa/nombre) para resolver el dueño de los documentos de flota
        // en lote, sin N+1. Mismo criterio que el tablero (ListExpiringDocuments).
        $labels = [
            'vehicle' => [],
            'driver' => [],
            'helper' => [],
        ];
        foreach ($vehicles as $vehicle) {
            $labels['vehicle'][$vehicle->id()->toString()] = $vehicle->plate()->value;
        }
        foreach ($drivers as $driver) {
            $labels['driver'][$driver->id()->toString()] = $driver->name();
        }
        foreach ($helpers as $helper) {
            $labels['helper'][$helper->id()->toString()] = $helper->name();
        }

        $results = [];

        foreach ($vehicles as $vehicle) {
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

        foreach ($drivers as $driver) {
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

        foreach ($helpers as $helper) {
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
                $ownerType = $document->ownerType()->value;
                $ownerId = $document->ownerId();
                $results[] = ExpiringDocumentView::forDocument(
                    $ownerType,
                    $ownerId,
                    $labels[$ownerType][$ownerId] ?? $ownerId,
                    $document->document()->type,
                    $document->document()->expiresOn->format('Y-m-d'),
                    $document->isExpiredAt($ref),
                );
            }
        }

        return $results;
    }

    /**
     * Cuenta los documentos de un umbral dado dentro del resumen de la última corrida.
     *
     * @param list<array{threshold?: string}> $documents
     */
    private function countThreshold(array $documents, string $threshold): int
    {
        return count(array_filter(
            $documents,
            static fn (array $document): bool => ($document['threshold'] ?? null) === $threshold,
        ));
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
     * Cantidad de documentos que vencen dentro de los próximos 7 días (aún vigentes) en la última corrida.
     */
    public function lastDueSoon(): int
    {
        return $this->lastDueSoon;
    }

    /**
     * Cantidad de documentos que vencen dentro de la ventana (hasta 30 días) pero a más de 7 días.
     */
    public function lastUpcoming(): int
    {
        return $this->lastUpcoming;
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
