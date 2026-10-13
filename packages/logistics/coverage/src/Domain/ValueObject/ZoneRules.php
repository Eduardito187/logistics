<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\ValueObject;

use Logistics\Coverage\Domain\Exception\CoverageException;

/**
 * Reglas de operación de una zona: con qué nodo se atiende, qué servicios ofrece, cuánto tarda de
 * base, qué límites tiene por producto, en qué ventanas horarias y qué días.
 *
 * Es configuración versionada (principio 5 de la arquitectura), no código: por eso vive como VO y
 * se persiste en una columna jsonb. No incluye cupos ni capacidad: eso es Fase 2.
 */
final readonly class ZoneRules
{
    /**
     * @param array<int, string>     $services      códigos de servicio habilitados (p. ej. "STD", "EXPRESS")
     * @param array<string, int>     $productLimits límite de unidades por SKU/producto
     * @param array<int, TimeWindow> $timeWindows   ventanas horarias de atención dentro del día
     */
    private function __construct(
        public ?string $nodeId,
        public array $services,
        public int $baseMinutes,
        public array $productLimits,
        public array $timeWindows,
        public ServiceDays $serviceDays,
    ) {}

    /**
     * @param array<int, string>     $services
     * @param array<string, int>     $productLimits
     * @param array<int, TimeWindow> $timeWindows
     */
    public static function define(
        ?string $nodeId,
        array $services,
        int $baseMinutes,
        array $productLimits,
        array $timeWindows,
        ServiceDays $serviceDays,
    ): self {
        if ($baseMinutes < 0) {
            throw CoverageException::because("el tiempo base no puede ser negativo: {$baseMinutes}");
        }
        foreach ($services as $service) {
            if ($service === '') {
                throw CoverageException::because('un código de servicio no puede estar vacío');
            }
        }
        foreach ($productLimits as $sku => $limit) {
            if ($limit < 0) {
                throw CoverageException::because("el límite del producto {$sku} no puede ser negativo: {$limit}");
            }
        }

        return new self(
            $nodeId,
            array_values($services),
            $baseMinutes,
            $productLimits,
            array_values($timeWindows),
            $serviceDays,
        );
    }

    /**
     * Reconstruye desde su forma primitiva (jsonb o payload de comando). Inverso de toArray().
     *
     * Tolerante a propósito: la entrada viene de jsonb o de un comando, donde los tipos llegan como
     * mixed. Se narrowea campo por campo y se ignora lo que no encaja, sin romper.
     *
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $node = $data['nodeId'] ?? null;
        $nodeId = is_string($node) && $node !== '' ? $node : null;

        $services = [];
        $rawServices = $data['services'] ?? [];
        if (is_array($rawServices)) {
            foreach ($rawServices as $service) {
                if (is_string($service) && $service !== '') {
                    $services[] = $service;
                }
            }
        }

        $rawBase = $data['baseMinutes'] ?? 0;
        $baseMinutes = is_numeric($rawBase) ? (int) $rawBase : 0;

        $productLimits = [];
        $rawLimits = $data['productLimits'] ?? [];
        if (is_array($rawLimits)) {
            foreach ($rawLimits as $sku => $limit) {
                if (is_numeric($limit)) {
                    $productLimits[(string) $sku] = (int) $limit;
                }
            }
        }

        $windows = [];
        $rawWindows = $data['timeWindows'] ?? [];
        if (is_array($rawWindows)) {
            foreach ($rawWindows as $window) {
                if (is_array($window) && isset($window['start'], $window['end']) && is_string($window['start']) && is_string($window['end'])) {
                    $windows[] = TimeWindow::of($window['start'], $window['end']);
                }
            }
        }

        $days = [];
        $rawDays = $data['serviceDays'] ?? [];
        if (is_array($rawDays)) {
            foreach ($rawDays as $day) {
                if (is_numeric($day)) {
                    $days[] = (int) $day;
                }
            }
        }

        return self::define($nodeId, $services, $baseMinutes, $productLimits, $windows, ServiceDays::fromArray($days));
    }

    /**
     * Forma primitiva para persistir en jsonb (regla 7 del CLAUDE.md: se cachea/persiste el array,
     * nunca el objeto serializado).
     *
     * @return array{
     *     nodeId: string|null,
     *     services: array<int, string>,
     *     baseMinutes: int,
     *     productLimits: array<string, int>,
     *     timeWindows: array<int, array{start: string, end: string}>,
     *     serviceDays: array<int, int>
     * }
     */
    public function toArray(): array
    {
        return [
            'nodeId' => $this->nodeId,
            'services' => $this->services,
            'baseMinutes' => $this->baseMinutes,
            'productLimits' => $this->productLimits,
            'timeWindows' => array_map(static fn (TimeWindow $w): array => $w->toArray(), $this->timeWindows),
            'serviceDays' => $this->serviceDays->toArray(),
        ];
    }
}
