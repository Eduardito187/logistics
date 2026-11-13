<?php

declare(strict_types=1);

use Logistics\SapConnector\Application\Command\ImportSapOrders\ImportSapOrdersHandler;
use Logistics\SapConnector\Domain\Contract\MaterialMappingRepository;
use Logistics\SapConnector\Domain\Contract\SapClient;
use Logistics\SapConnector\Domain\Exception\SapConnectorException;
use Logistics\SapConnector\Domain\Model\MaterialMapping;
use Logistics\SapConnector\Domain\Model\SapNotification;
use Logistics\SapConnector\Domain\Model\StockTransfer;
use Logistics\SapConnector\Domain\ValueObject\MaterialMappingId;
use Logistics\SapConnector\Domain\ValueObject\SapMaterial;
use Logistics\SapConnector\Domain\ValueObject\SapResponse;
use Logistics\SapConnector\Infrastructure\Queue\ImportSapOrdersJob;
use Psr\Log\AbstractLogger;

/**
 * Job de la cola `sap` que lee pedidos de SAP: NUNCA lanza. Se ejercitan el camino normal (SAP sin
 * pedidos → info en el log) y la red de seguridad (SAP caído → warning, sin propagar). PHP puro.
 */

/**
 * Logger espía en memoria.
 */
function importJobLogger(): AbstractLogger
{
    return new class extends AbstractLogger
    {
        /** @var list<array{level: mixed, message: string}> */
        public array $records = [];

        public function log($level, $message, array $context = []): void
        {
            $this->records[] = ['level' => $level, 'message' => (string) $message];
        }
    };
}

/**
 * Mapa de traducción vacío (no se usa cuando SAP no devuelve pedidos).
 */
function importJobMappings(): MaterialMappingRepository
{
    return new class implements MaterialMappingRepository
    {
        public function nextIdentity(): MaterialMappingId
        {
            return MaterialMappingId::generate();
        }

        public function save(MaterialMapping $mapping): void {}

        public function findBySku(string $sku): ?MaterialMapping
        {
            return null;
        }

        public function findByMaterial(SapMaterial $material): ?MaterialMapping
        {
            return null;
        }

        public function all(): array
        {
            return [];
        }
    };
}

/**
 * Puerto SAP que devuelve los pedidos dados o revienta (simula SAP caído).
 *
 * @param list<array<string, mixed>> $orders
 */
function importJobSapClient(array $orders = [], bool $throws = false): SapClient
{
    return new class($orders, $throws) implements SapClient
    {
        /**
         * @param list<array<string, mixed>> $orders
         */
        public function __construct(
            private readonly array $orders,
            private readonly bool $throws,
        ) {}

        public function pushStockTransfer(StockTransfer $transfer): void {}

        public function fetchOrders(): array
        {
            if ($this->throws) {
                throw SapConnectorException::because('SAP no responde');
            }

            return $this->orders;
        }

        public function notifyEvent(SapNotification $notification): SapResponse
        {
            return new SapResponse(204, '');
        }
    };
}

it('al terminar la importación deja en el log la cantidad de pedidos traducidos', function (): void {
    $handler = new ImportSapOrdersHandler(importJobSapClient([]), importJobMappings());
    $logger = importJobLogger();

    (new ImportSapOrdersJob)->handle($handler, $logger);

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('info')
        ->and($logger->records[0]['message'])->toBe('SAP orders import finished')
        ->and($handler->importedOrders())->toBe([]);
});

it('se traga un SAP caído y lo deja como warning (nunca lanza hacia la cola)', function (): void {
    $handler = new ImportSapOrdersHandler(importJobSapClient(throws: true), importJobMappings());
    $logger = importJobLogger();

    (new ImportSapOrdersJob)->handle($handler, $logger);

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('warning')
        ->and($logger->records[0]['message'])->toBe('SAP orders import failed');
});

it('un solo intento de cola y ventana de unicidad definida', function (): void {
    $job = new ImportSapOrdersJob;

    expect($job->tries)->toBe(1)
        ->and($job->uniqueFor)->toBe(300);
});
