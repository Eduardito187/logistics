<?php

declare(strict_types=1);

namespace Logistics\Fleet\Application\Query\ListExpiringDocuments;

use DateTimeImmutable;
use Logistics\Fleet\Application\ReadModel\ExpiringDocumentView;
use Logistics\Fleet\Domain\Contract\DocumentRepository;
use Logistics\Fleet\Domain\Contract\DriverRepository;
use Logistics\Fleet\Domain\Contract\HelperRepository;
use Logistics\Fleet\Domain\Contract\VehicleRepository;
use Logistics\Shared\Application\Query\Query;
use Logistics\Shared\Application\Query\QueryHandler;

/**
 * Recorre vehículos, choferes, ayudantes y los documentos de flota con archivo, y junta los que vencen
 * dentro de la ventana pedida (incluidos los ya vencidos).
 *
 * Es una lectura de conveniencia para el tablero de vencimientos; no cambia estado. Reúne las cuatro
 * fuentes de vencimiento del contexto en una sola lista para alertar en un solo lugar.
 *
 * @implements QueryHandler<ListExpiringDocuments, list<ExpiringDocumentView>>
 */
final readonly class ListExpiringDocumentsHandler implements QueryHandler
{
    public function __construct(
        private VehicleRepository $vehicles,
        private DriverRepository $drivers,
        private HelperRepository $helpers,
        private DocumentRepository $documents,
    ) {}

    /**
     * @return list<ExpiringDocumentView>
     */
    public function handle(Query $query): array
    {
        assert($query instanceof ListExpiringDocuments);

        $ref = $query->referenceDate !== null
            ? new DateTimeImmutable($query->referenceDate)
            : new DateTimeImmutable('today');

        $vehicles = $this->vehicles->all();
        $drivers = $this->drivers->all();
        $helpers = $this->helpers->all();

        // Mapas id → etiqueta legible (placa/nombre) para resolver el dueño de los documentos de flota
        // en lote, sin N+1. Mismo criterio que la alerta programada.
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
            foreach ($vehicle->documentsExpiringWithin($query->days, $ref) as $document) {
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
            if ($license->expiresWithin($query->days, $ref)) {
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
            foreach ($helper->documentsExpiringWithin($query->days, $ref) as $document) {
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
            if ($document->expiresWithin($query->days, $ref)) {
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
}
