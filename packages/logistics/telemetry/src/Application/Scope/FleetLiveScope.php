<?php

declare(strict_types=1);

namespace Logistics\Telemetry\Application\Scope;

/**
 * Alcance de la flota en vivo para quien consulta: qué camiones puede ver según su rol y su ciudad.
 *
 * Es la MISMA regla con la que el host autoriza los canales del tema `fleet` (`fleet.all` /
 * `fleet.city.<departamento>`), para que la consulta y el socket nunca se contradigan:
 *  - admin y supervisor: todas las ciudades;
 *  - planificador con ciudad: solo los camiones cuya sede (nodo base) es de su departamento;
 *  - planificador sin ciudad: todas, salvo en modo estricto (ahí, ninguno);
 *  - cualquier otro rol: ninguno.
 *
 * La ciudad es el código de departamento (`santa_cruz`, `la_paz`, …), el mismo de `users.city` y de
 * `network_nodes.department`. PHP puro: no conoce al usuario ni al framework; viaja dentro de la consulta.
 */
final readonly class FleetLiveScope
{
    private const string MODE_ALL = 'all';

    private const string MODE_CITY = 'city';

    private const string MODE_NONE = 'none';

    /** Roles que ven la flota de todas las ciudades. */
    private const array ALL_CITIES_ROLES = ['admin', 'supervisor'];

    /** Rol acotado a su ciudad. */
    private const string CITY_ROLE = 'planificador';

    private function __construct(
        private string $mode,
        private ?string $city,
    ) {}

    /** Sin restricción: toda la flota (procesos internos, o cuando no hay un usuario a quien acotar). */
    public static function unrestricted(): self
    {
        return new self(self::MODE_ALL, null);
    }

    /** Solo los camiones con sede en ese departamento. Un código vacío o inválido no deja ver nada. */
    public static function forCity(string $city): self
    {
        $code = self::cityCode($city);

        return $code === null ? self::none() : new self(self::MODE_CITY, $code);
    }

    /** Ningún camión. */
    public static function none(): self
    {
        return new self(self::MODE_NONE, null);
    }

    /**
     * Alcance a partir de la identidad de quien consulta.
     *
     * @param list<string> $roles             valores de los roles del usuario (`admin`, `planificador`, …)
     * @param string|null  $city              código de departamento del usuario, o null si no tiene
     * @param bool         $strictWithoutCity con true, un planificador sin ciudad no ve nada
     */
    public static function fromIdentity(array $roles, ?string $city, bool $strictWithoutCity = false): self
    {
        $roles = array_map(static fn (string $role): string => strtolower(trim($role)), $roles);

        if (array_intersect(self::ALL_CITIES_ROLES, $roles) !== []) {
            return self::unrestricted();
        }

        if (!in_array(self::CITY_ROLE, $roles, true)) {
            return self::none();
        }

        return self::forCityRole($city, $strictWithoutCity);
    }

    /**
     * Alcance del rol acotado a su ciudad: su departamento si tiene uno válido; sin ciudad, todas (o
     * ninguna en modo estricto).
     */
    private static function forCityRole(?string $city, bool $strictWithoutCity): self
    {
        $code = self::cityCode($city);
        if ($code !== null) {
            return new self(self::MODE_CITY, $code);
        }

        return $strictWithoutCity ? self::none() : self::unrestricted();
    }

    /** ¿Ve la flota de todas las ciudades? */
    public function seesAllCities(): bool
    {
        return $this->mode === self::MODE_ALL;
    }

    /** ¿No ve ningún camión? */
    public function seesNothing(): bool
    {
        return $this->mode === self::MODE_NONE;
    }

    /** Departamento al que está acotado, o null si ve todas las ciudades o ninguna. */
    public function city(): ?string
    {
        return $this->city;
    }

    /**
     * Clave estable del alcance (`all`, `none`, `city:santa_cruz`): entra en el ETag de la respuesta para que
     * dos personas con distinto alcance nunca compartan validador.
     */
    public function key(): string
    {
        return $this->city === null ? $this->mode : $this->mode.':'.$this->city;
    }

    /** Código de departamento normalizado (minúsculas, letras, dígitos y guion bajo), o null. */
    private static function cityCode(?string $city): ?string
    {
        if ($city === null) {
            return null;
        }

        $code = strtolower(trim($city));

        return $code !== '' && preg_match('/^[a-z0-9_]{1,40}$/', $code) === 1 ? $code : null;
    }
}
