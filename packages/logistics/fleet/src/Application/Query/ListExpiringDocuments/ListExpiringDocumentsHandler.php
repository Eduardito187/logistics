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

        $results = [];

        foreach ($this->vehicles->all() as $vehicle) {
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

        foreach ($this->drivers->all() as $driver) {
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

        foreach ($this->helpers->all() as $helper) {
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
}
