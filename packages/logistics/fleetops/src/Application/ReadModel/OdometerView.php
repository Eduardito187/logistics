<?php

declare(strict_types=1);

namespace Logistics\FleetOps\Application\ReadModel;

use DateTimeImmutable;
use Logistics\FleetOps\Domain\Model\OdometerReading;

/**
 * Proyección de lectura de una lectura de odómetro para listados, admin y API. Primitivos: no expone
 * objetos de dominio. La fecha sale en ISO 8601 (ATOM).
 */
final readonly class OdometerView
{
    private function __construct(
        public string $id,
        public string $vehicleId,
        public int $km,
        public string $source,
        public string $readAt,
        public ?string $note,
    ) {}

    public static function fromDomain(OdometerReading $reading): self
    {
        return new self(
            $reading->id()->toString(),
            $reading->vehicleId(),
            $reading->odometer()->km,
            $reading->source()->value,
            $reading->readAt()->format(DateTimeImmutable::ATOM),
            $reading->note(),
        );
    }

    /**
     * @return array{id: string, vehicle_id: string, km: int, source: string, read_at: string, note: string|null}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'vehicle_id' => $this->vehicleId,
            'km' => $this->km,
            'source' => $this->source,
            'read_at' => $this->readAt,
            'note' => $this->note,
        ];
    }
}
