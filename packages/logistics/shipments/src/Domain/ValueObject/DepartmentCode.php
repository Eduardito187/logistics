<?php

declare(strict_types=1);

namespace Logistics\Shipments\Domain\ValueObject;

/**
 * Código de DEPARTAMENTO de Bolivia (la «ciudad» operativa del personal): el mismo slug que usa la red en
 * `network_nodes.department` (`la_paz`, `santa_cruz`, `cochabamba`, …).
 *
 * Es el vocabulario común del alcance por ciudad: el departamento de origen y de destino de un envío y la
 * ciudad asignada a un usuario se comparan SIEMPRE por este código. Dominio puro: solo normaliza texto.
 */
final class DepartmentCode
{
    /** Departamentos de Bolivia (código canónico). */
    public const array ALL = [
        'la_paz', 'santa_cruz', 'cochabamba', 'oruro', 'potosi', 'chuquisaca', 'tarija', 'beni', 'pando',
    ];

    /**
     * Ciudad o alias conocido (ya normalizado a slug) → departamento. Una ciudad pertenece a su
     * departamento aunque no sea la capital: El Alto es `la_paz`, Montero es `santa_cruz`.
     */
    private const array CITY_TO_DEPARTMENT = [
        'lpz' => 'la_paz',
        'el_alto' => 'la_paz',
        'viacha' => 'la_paz',
        'achocalla' => 'la_paz',
        'mecapaca' => 'la_paz',
        'scz' => 'santa_cruz',
        'santa_cruz_de_la_sierra' => 'santa_cruz',
        'montero' => 'santa_cruz',
        'warnes' => 'santa_cruz',
        'cotoca' => 'santa_cruz',
        'la_guardia' => 'santa_cruz',
        'el_torno' => 'santa_cruz',
        'camiri' => 'santa_cruz',
        'puerto_suarez' => 'santa_cruz',
        'san_ignacio_de_velasco' => 'santa_cruz',
        'cbba' => 'cochabamba',
        'quillacollo' => 'cochabamba',
        'sacaba' => 'cochabamba',
        'tiquipaya' => 'cochabamba',
        'colcapirhua' => 'cochabamba',
        'vinto' => 'cochabamba',
        'sucre' => 'chuquisaca',
        'villazon' => 'potosi',
        'tupiza' => 'potosi',
        'uyuni' => 'potosi',
        'yacuiba' => 'tarija',
        'villamontes' => 'tarija',
        'bermejo' => 'tarija',
        'trinidad' => 'beni',
        'riberalta' => 'beni',
        'guayaramerin' => 'beni',
        'cobija' => 'pando',
    ];

    /**
     * Slug de un texto: minúsculas, sin tildes y con guiones bajos («La Paz» → `la_paz`, «Potosí» →
     * `potosi`). Null si el texto no tiene nada utilizable.
     */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $lower = mb_strtolower(trim($value));
        $plain = strtr($lower, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n']);
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '_', $plain), '_');

        return $slug === '' ? null : $slug;
    }

    /**
     * Departamento al que pertenece una ciudad conocida (o el propio departamento si el texto ya lo es).
     * Null si la ciudad no se reconoce.
     */
    public static function fromCity(?string $city): ?string
    {
        $slug = self::normalize($city);
        if ($slug === null) {
            return null;
        }

        if (in_array($slug, self::ALL, true)) {
            return $slug;
        }

        return self::CITY_TO_DEPARTMENT[$slug] ?? null;
    }

    /**
     * Código de departamento de un valor guardado o asignado (la ciudad de un usuario, el departamento de un
     * nodo): reconoce ciudades y alias, y si no, conserva el slug tal cual para que dos valores iguales
     * sigan coincidiendo aunque no estén en el catálogo.
     */
    public static function of(?string $value): ?string
    {
        return self::fromCity($value) ?? self::normalize($value);
    }
}
