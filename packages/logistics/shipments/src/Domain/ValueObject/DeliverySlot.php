<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

use DateTimeImmutable;
use Logistics\Shipments\Domain\Exception\ShipmentsException;

/**
 * Franja de entrega comprometida con el cliente: un día (sin hora) más una ventana horaria explícita
 * (inicio y fin en 'HH:MM', 24h). Es el slot que el envío "lleva" una vez reservado el cupo de
 * despacho.
 *
 * El inicio debe ser estrictamente anterior al fin dentro del mismo día (no cruza medianoche), igual
 * que la franja de capacity. La fecha se normaliza a 'Y-m-d' para que dos slots del mismo día comparen
 * por valor sin depender de la hora ni de la zona horaria.
 */
final readonly class DeliverySlot
{
    private const string DATE_FORMAT = 'Y-m-d';

    private const string TIME_PATTERN = '/^([01]\d|2[0-3]):[0-5]\d$/';

    private function __construct(
        public string $date,
        public string $windowStart,
        public string $windowEnd,
    ) {}

    /**
     * Construye la franja desde primitivos: fecha 'Y-m-d' y ventana 'HH:MM'-'HH:MM'. Valida que la
     * fecha sea real y que la ventana empiece antes de terminar.
     */
    public static function of(string $date, string $windowStart, string $windowEnd): self
    {
        $date = trim($date);
        $parsed = DateTimeImmutable::createFromFormat('!'.self::DATE_FORMAT, $date);
        if ($parsed === false || $parsed->format(self::DATE_FORMAT) !== $date) {
            throw ShipmentsException::because("fecha de entrega inválida (se espera Y-m-d): {$date}");
        }

        $start = trim($windowStart);
        $end = trim($windowEnd);
        if (preg_match(self::TIME_PATTERN, $start) !== 1 || preg_match(self::TIME_PATTERN, $end) !== 1) {
            throw ShipmentsException::because("ventana de entrega con formato inválido (HH:MM): {$windowStart}-{$windowEnd}");
        }
        if (self::minutes($start) >= self::minutes($end)) {
            throw ShipmentsException::because("la ventana de entrega debe empezar antes de terminar: {$start}-{$end}");
        }

        return new self($date, $start, $end);
    }

    /**
     * Reconstruye desde las tres columnas nullable de la base. Devuelve null si falta alguna (envío sin
     * franja todavía); exige las tres juntas para formar un slot válido.
     */
    public static function fromNullable(?string $date, ?string $windowStart, ?string $windowEnd): ?self
    {
        if ($date === null || $windowStart === null || $windowEnd === null) {
            return null;
        }

        return self::of(substr($date, 0, 10), $windowStart, $windowEnd);
    }

    /**
     * Etiqueta de la ventana en el formato canónico de capacity ('HH:MM-HH:MM'), para cruzar la franja
     * con un bucket de cupo sin reinterpretarla.
     */
    public function windowLabel(): string
    {
        return "{$this->windowStart}-{$this->windowEnd}";
    }

    public function equals(self $other): bool
    {
        return $this->date === $other->date
            && $this->windowStart === $other->windowStart
            && $this->windowEnd === $other->windowEnd;
    }

    /**
     * Forma canónica "YYYY-MM-DD|HH:MM-HH:MM", útil para logs, claves e idempotencia.
     */
    public function toCanonical(): string
    {
        return $this->date.'|'.$this->windowLabel();
    }

    /**
     * Minutos desde la medianoche de una hora 'HH:MM' (ya validada por el patrón).
     */
    private static function minutes(string $hhmm): int
    {
        [$h, $m] = explode(':', $hhmm);

        return ((int) $h) * 60 + (int) $m;
    }
}
