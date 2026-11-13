<?php

declare(strict_types=1);

namespace Logistics\Promise\Application\ReadModel;

/**
 * Modelo de lectura de un DÍA ofrecible a un cliente para su municipio: municipio resuelto, fecha y cuántas
 * ventanas con cupo tiene ese día. Forma plana en primitivos, SIN PII: es lo que el canal muestra cuando
 * ofrece solo fechas (la ventana la elige luego el sistema).
 */
final readonly class DeliveryDateView
{
    public function __construct(
        public string $municipalityId,
        public string $date,
        public int $windowCount,
    ) {}

    /**
     * @return array{municipality_id: string, date: string, window_count: int}
     */
    public function toArray(): array
    {
        return [
            'municipality_id' => $this->municipalityId,
            'date' => $this->date,
            'window_count' => $this->windowCount,
        ];
    }
}
