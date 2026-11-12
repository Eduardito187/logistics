<?php

declare(strict_types=1);

namespace Logistics\Carriers\Application\ReadModel;

/**
 * Proyección de lectura de un corredor tarifado. Primitivos: no expone objetos de dominio.
 *
 * `from` / `to` son EXACTAMENTE el slug guardado en la tarifa (el que acepta `GET {carrierId}/tariff`);
 * `fromLabel` / `toLabel` son su nombre legible (nombre del nodo de network si el slug coincide con un
 * código o id de nodo; si no, el slug humanizado).
 */
final readonly class CarrierCorridorView
{
    /**
     * @param list<string> $vehicleClasses
     */
    public function __construct(
        public string $from,
        public string $to,
        public string $fromLabel,
        public string $toLabel,
        public string $corridorType,
        public array $vehicleClasses,
    ) {}

    /**
     * @return array{from: string, to: string, from_label: string, to_label: string, corridor_type: string, vehicle_classes: list<string>}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'from_label' => $this->fromLabel,
            'to_label' => $this->toLabel,
            'corridor_type' => $this->corridorType,
            'vehicle_classes' => $this->vehicleClasses,
        ];
    }
}
