<?php

declare(strict_types=1);

namespace Logistics\Shipments\Application\ReadModel;

/**
 * Dirección ESTRUCTURADA de un envío (pedido #26 de la app): calle, número, barrio/zona, referencia y
 * ciudad, más una `short_address` lista para mostrar en una línea.
 *
 * Ninguna fuente de ingreso (SAP, Magento, planilla, commerce) manda hoy la dirección por partes: llega
 * un texto libre (`address_text`) y una referencia opcional. Por eso las partes se obtienen con un
 * análisis BEST-EFFORT del texto en el lado de lectura (sin I/O, determinista), con las convenciones
 * bolivianas habituales ("Av. 6 de Agosto 2170, Sopocachi, La Paz", "Calle Las Lilas 245, Barrio Urbarí,
 * Santa Cruz de la Sierra", "Av. Irala 2do anillo N° 452, Santa Cruz"). Cualquier parte que no se pueda
 * reconocer con seguridad queda null; `short_address` nunca queda vacía (cae al texto original).
 *
 * Así la app deja de mantener su propio diccionario de ciudades. Si en el futuro el ingreso trae las
 * partes estructuradas, esta vista puede leerlas de columnas sin cambiar el contrato.
 */
final readonly class AddressPartsView
{
    /** Largo máximo de `short_address` (una línea de pantalla de teléfono). */
    private const SHORT_MAX_LENGTH = 80;

    /**
     * Ciudades/municipios de Bolivia reconocidos: clave normalizada (minúsculas, sin tildes) → nombre
     * canónico a devolver.
     */
    private const CITIES = [
        'la paz' => 'La Paz',
        'el alto' => 'El Alto',
        'santa cruz' => 'Santa Cruz',
        'santa cruz de la sierra' => 'Santa Cruz',
        'scz' => 'Santa Cruz',
        'cochabamba' => 'Cochabamba',
        'cbba' => 'Cochabamba',
        'sucre' => 'Sucre',
        'oruro' => 'Oruro',
        'potosi' => 'Potosí',
        'tarija' => 'Tarija',
        'trinidad' => 'Trinidad',
        'cobija' => 'Cobija',
        'montero' => 'Montero',
        'warnes' => 'Warnes',
        'cotoca' => 'Cotoca',
        'la guardia' => 'La Guardia',
        'el torno' => 'El Torno',
        'quillacollo' => 'Quillacollo',
        'sacaba' => 'Sacaba',
        'tiquipaya' => 'Tiquipaya',
        'colcapirhua' => 'Colcapirhua',
        'vinto' => 'Vinto',
        'viacha' => 'Viacha',
        'achocalla' => 'Achocalla',
        'mecapaca' => 'Mecapaca',
        'yacuiba' => 'Yacuiba',
        'villamontes' => 'Villamontes',
        'bermejo' => 'Bermejo',
        'riberalta' => 'Riberalta',
        'guayaramerin' => 'Guayaramerín',
        'villazon' => 'Villazón',
        'tupiza' => 'Tupiza',
        'uyuni' => 'Uyuni',
        'camiri' => 'Camiri',
        'puerto suarez' => 'Puerto Suárez',
        'san ignacio de velasco' => 'San Ignacio de Velasco',
    ];

    /** Segmentos finales que no aportan (país) y se descartan antes de buscar la ciudad. */
    private const COUNTRY_SEGMENTS = ['bolivia', 'bo'];

    /** Prefijos que identifican un barrio/zona. */
    private const NEIGHBORHOOD_PREFIX = '/^(barrio|b\/|zona|urb\.?|urbanizaci[oó]n|villa|condominio|residencial|plan\s+\d+)\b/iu';

    /** Segmentos que NO son barrio aunque estén en el medio (anillos, UV, manzanas, entre-calles, km). */
    private const NOT_NEIGHBORHOOD = '/(anillo|^u\.?\s?v\.?\b|^mz|^manzana|^calle|^c\/|^entre|^km|^lote|^piso|^dpto|^depto|^edif)/iu';

    /** Número explícito: "N° 1200", "Nº 45", "No. 12", "Nro 8", "#8050". */
    private const EXPLICIT_NUMBER = '/(?<!\p{L})(?:N[°ºo]\.?|Nro\.?|No\.|#)\s*(\d+[A-Za-z]?(?:-\d+)?)\b/u';

    /** Número al final de la calle: "Av. Arce 2525". */
    private const TRAILING_NUMBER = '/^(.*\S)\s+(\d{1,5}[A-Za-z]?)$/u';

    /** Palabras tras las que un número final NO es la numeración de la puerta ("UV 160", "Km 9"). */
    private const NUMBER_BLOCKERS = ['uv', 'u.v', 'mz', 'mza', 'manzana', 'km', 'plan', 'zona', 'distrito', 'lote', 'anillo', 'piso'];

    public function __construct(
        public ?string $street,
        public ?string $number,
        public ?string $neighborhood,
        public ?string $reference,
        public ?string $city,
        public string $shortAddress,
    ) {}

    /**
     * Analiza el texto libre de la dirección (y la referencia aparte) en sus partes, best-effort.
     */
    public static function fromText(string $text, ?string $reference = null): self
    {
        $reference = $reference !== null && trim($reference) !== '' ? trim($reference) : null;
        $original = trim((string) preg_replace('/\s+/u', ' ', $text));

        $segments = array_values(array_filter(
            array_map(static fn (string $segment): string => trim($segment), explode(',', $original)),
            static fn (string $segment): bool => $segment !== '',
        ));

        if ($segments === []) {
            return new self(null, null, null, $reference, null, $original);
        }

        // Se descarta el país al final ("..., Bolivia").
        while (count($segments) > 1 && in_array(self::normalize($segments[count($segments) - 1]), self::COUNTRY_SEGMENTS, true)) {
            array_pop($segments);
        }

        // Ciudad: el último segmento, si es una ciudad conocida (nunca el primero, que es la calle,
        // salvo que la dirección sea solo la ciudad).
        $city = null;
        if (count($segments) > 1) {
            $city = self::cityOf($segments[count($segments) - 1]);
            if ($city !== null) {
                array_pop($segments);
            }
        } else {
            $city = self::cityOf($segments[0]);
            if ($city !== null) {
                return new self(null, null, null, $reference, $city, $city);
            }
        }

        [$street, $number] = self::streetAndNumber($segments[0]);
        $neighborhood = self::neighborhoodOf(array_slice($segments, 1));

        $base = trim(implode(' ', array_filter([$street, $number], static fn (?string $part): bool => $part !== null)));
        if ($base === '') {
            $base = $segments[0];
        }

        $suffix = $neighborhood ?? $city;
        $short = $suffix !== null ? $base.', '.$suffix : $base;

        return new self($street, $number, $neighborhood, $reference, $city, self::truncate($short !== '' ? $short : $original));
    }

    /**
     * @return array{street: string|null, number: string|null, neighborhood: string|null, reference: string|null, city: string|null}
     */
    public function toArray(): array
    {
        return [
            'street' => $this->street,
            'number' => $this->number,
            'neighborhood' => $this->neighborhood,
            'reference' => $this->reference,
            'city' => $this->city,
        ];
    }

    /**
     * Separa la calle de su numeración (explícita "N° 123" / "#123", o un número al final).
     *
     * @return array{0: string|null, 1: string|null}
     */
    private static function streetAndNumber(string $segment): array
    {
        if (preg_match(self::EXPLICIT_NUMBER, $segment, $match) === 1) {
            $street = trim((string) preg_replace(self::EXPLICIT_NUMBER, ' ', $segment, 1));
            $street = trim((string) preg_replace('/\s+/u', ' ', $street));

            return [$street !== '' ? $street : null, $match[1]];
        }

        if (preg_match(self::TRAILING_NUMBER, $segment, $match) === 1) {
            $words = explode(' ', $match[1]);
            $previous = self::normalize($words[count($words) - 1]);

            // "UV 160", "Km 9", "Plan 3000": el número no es la puerta.
            if (!in_array($previous, self::NUMBER_BLOCKERS, true) && count($words) > 1) {
                return [trim($match[1]), $match[2]];
            }
        }

        return [$segment, null];
    }

    /**
     * Barrio/zona entre la calle y la ciudad: primero uno con prefijo explícito ("Barrio X", "Zona Y");
     * si no hay, el primer segmento que no sea un anillo/UV/manzana/entre-calles.
     *
     * @param list<string> $middle
     */
    private static function neighborhoodOf(array $middle): ?string
    {
        foreach ($middle as $segment) {
            if (preg_match(self::NEIGHBORHOOD_PREFIX, $segment) === 1) {
                return $segment;
            }
        }

        foreach ($middle as $segment) {
            if (preg_match(self::NOT_NEIGHBORHOOD, $segment) !== 1 && preg_match('/^\d+$/', $segment) !== 1) {
                return $segment;
            }
        }

        return null;
    }

    private static function cityOf(string $segment): ?string
    {
        return self::CITIES[self::normalize($segment)] ?? null;
    }

    /** Minúsculas, sin tildes, sin puntos finales y con espacios colapsados (para comparar). */
    private static function normalize(string $value): string
    {
        $lower = mb_strtolower(trim($value));
        $plain = strtr($lower, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);

        return trim((string) preg_replace('/\s+/u', ' ', rtrim($plain, '.')));
    }

    private static function truncate(string $value): string
    {
        if (mb_strlen($value) <= self::SHORT_MAX_LENGTH) {
            return $value;
        }

        return rtrim(mb_substr($value, 0, self::SHORT_MAX_LENGTH - 1)).'…';
    }
}
