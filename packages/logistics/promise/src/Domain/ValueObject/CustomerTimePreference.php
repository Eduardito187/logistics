<?php

declare(strict_types=1);

namespace Logistics\Promise\Domain\ValueObject;

use Logistics\Promise\Domain\Exception\PromiseException;

/**
 * Preferencia horaria del cliente para la entrega, como restricción BLANDA de la selección de ventana:
 * no descarta ventanas, las puntúa. Formas soportadas:
 *
 *  - `none`            → sin preferencia (puntaje neutro para todas las ventanas).
 *  - `morning`         → prefiere la mañana (00:00–12:00).
 *  - `afternoon`       → prefiere la tarde (12:00–24:00).
 *  - `after(HH:MM)`    → prefiere ventanas que empiezan a esa hora o después.
 *  - `before(HH:MM)`   → prefiere ventanas que terminan a esa hora o antes.
 *  - `between(a, b)`   → prefiere ventanas que solapan el rango [a, b].
 *
 * Inmutable y en primitivos (`toArray()`): viaja desde el canal como texto y nunca interpreta el formato
 * de franja de capacity, solo compara minutos del día. El puntaje que devuelve `score()` está en 0..1.
 */
final readonly class CustomerTimePreference
{
    public const string KIND_NONE = 'none';

    public const string KIND_MORNING = 'morning';

    public const string KIND_AFTERNOON = 'afternoon';

    public const string KIND_AFTER = 'after';

    public const string KIND_BEFORE = 'before';

    public const string KIND_BETWEEN = 'between';

    /** Mediodía en minutos del día, frontera entre mañana y tarde. */
    private const int NOON_MINUTE = 12 * 60;

    /** Minutos en un día completo. */
    private const int DAY_MINUTES = 24 * 60;

    private function __construct(
        public string $kind,
        public ?int $fromMinute,
        public ?int $toMinute,
    ) {}

    public static function none(): self
    {
        return new self(self::KIND_NONE, null, null);
    }

    public static function morning(): self
    {
        return new self(self::KIND_MORNING, 0, self::NOON_MINUTE);
    }

    public static function afternoon(): self
    {
        return new self(self::KIND_AFTERNOON, self::NOON_MINUTE, self::DAY_MINUTES);
    }

    public static function after(string $time): self
    {
        return new self(self::KIND_AFTER, self::minuteOf($time), self::DAY_MINUTES);
    }

    public static function before(string $time): self
    {
        return new self(self::KIND_BEFORE, 0, self::minuteOf($time));
    }

    public static function between(string $from, string $to): self
    {
        $fromMinute = self::minuteOf($from);
        $toMinute = self::minuteOf($to);
        if ($toMinute <= $fromMinute) {
            throw PromiseException::because("rango de preferencia inválido: {$from} no es anterior a {$to}");
        }

        return new self(self::KIND_BETWEEN, $fromMinute, $toMinute);
    }

    /**
     * Reconstruye la preferencia desde primitivos del canal. Forma tolerante: ausente/`none`/forma
     * desconocida → sin preferencia, para que un dato pobre nunca rompa la reserva.
     *
     * @param array{kind?: string|null, from?: string|null, to?: string|null}|null $data
     */
    public static function fromArray(?array $data): self
    {
        $kind = isset($data['kind']) && is_string($data['kind']) ? strtolower(trim($data['kind'])) : self::KIND_NONE;
        $from = isset($data['from']) && is_string($data['from']) ? $data['from'] : null;
        $to = isset($data['to']) && is_string($data['to']) ? $data['to'] : null;

        return match ($kind) {
            self::KIND_MORNING => self::morning(),
            self::KIND_AFTERNOON => self::afternoon(),
            self::KIND_AFTER => $from !== null ? self::after($from) : self::none(),
            self::KIND_BEFORE => $to !== null ? self::before($to) : self::none(),
            self::KIND_BETWEEN => $from !== null && $to !== null ? self::between($from, $to) : self::none(),
            default => self::none(),
        };
    }

    /**
     * Puntúa (0..1) cuánto honra una ventana [startMinute, endMinute] esta preferencia. Sin preferencia,
     * o si la ventana no trae bordes horarios parseables (`null`), devuelve un neutro 0.5 que no sesga la
     * elección. Con preferencia, es la fracción de la ventana que cae dentro del rango deseado.
     */
    public function score(?int $startMinute, ?int $endMinute): float
    {
        if ($this->kind === self::KIND_NONE) {
            return 1.0;
        }
        if ($startMinute === null || $endMinute === null || $endMinute <= $startMinute) {
            return 0.5;
        }

        $from = $this->fromMinute ?? 0;
        $to = $this->toMinute ?? self::DAY_MINUTES;

        $overlap = min($endMinute, $to) - max($startMinute, $from);
        if ($overlap <= 0) {
            return 0.0;
        }

        return $overlap / ($endMinute - $startMinute);
    }

    /**
     * @return array{kind: string, from: string|null, to: string|null}
     */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'from' => self::labelOf($this->fromMinute),
            'to' => self::labelOf($this->toMinute),
        ];
    }

    /**
     * Convierte 'HH:MM' a minutos del día (0..1440). Rechaza horas fuera de rango o mal formadas.
     */
    private static function minuteOf(string $time): int
    {
        $trimmed = trim($time);
        if (preg_match('/^(\d{1,2}):(\d{2})$/', $trimmed, $matches) !== 1) {
            throw PromiseException::because("hora de preferencia inválida (se esperaba HH:MM): {$time}");
        }

        $hours = (int) $matches[1];
        $minutes = (int) $matches[2];
        if ($hours > 24 || $minutes > 59 || ($hours === 24 && $minutes > 0)) {
            throw PromiseException::because("hora de preferencia fuera de rango: {$time}");
        }

        return $hours * 60 + $minutes;
    }

    private static function labelOf(?int $minute): ?string
    {
        if ($minute === null) {
            return null;
        }

        return sprintf('%02d:%02d', intdiv($minute, 60), $minute % 60);
    }
}
