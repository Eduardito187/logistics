<?php

declare(strict_types=1);

namespace Logistics\Network\Application\Contract;

/**
 * Directorio de nodos de la red en primitivos, para que el ruteo resuelva a qué departamento pertenece
 * un nodo, cuál es el almacén que concentra un departamento y dónde está el almacén de una
 * transportadora. Es un contrato de lectura (lado consulta de CQRS): la implementación consulta la base
 * sin hidratar agregados.
 *
 * Todas las lecturas son tolerantes: devuelven null cuando no hay un nodo que cumpla, para que el ruteo
 * degrade con elegancia en vez de romper.
 */
interface NetworkNodeDirectory
{
    /**
     * Código de departamento (`santa_cruz`, `la_paz`, …) del nodo indicado, o null si el nodo no existe
     * o no tiene departamento reconocible.
     */
    public function departmentOf(string $nodeId): ?string;

    /**
     * Nodo que concentra la distribución de un departamento: el primer centro de distribución o almacén
     * central ACTIVO de ese departamento, por código. Null si el departamento no tiene ninguno.
     *
     * @param  string      $department código o nombre del departamento (se normaliza)
     * @return string|null             id del nodo hub, o null
     */
    public function hubOfDepartment(string $department): ?string;

    /**
     * Almacén de una transportadora (nodo tipo carrier_hub) ACTIVO donde dejar la carga para el tramo
     * tercerizado. El `carrierId` se admite para elegir el almacén de ESA transportadora cuando se pueda;
     * hoy la red no vincula nodos a transportadoras, así que devuelve el primer carrier_hub activo por
     * código. Null si no hay ninguno sembrado.
     */
    public function carrierHub(?string $carrierId): ?string;
}
