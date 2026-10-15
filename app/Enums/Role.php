<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Roles operativos de la plataforma (P1-AUTH-03).
 *
 * El valor del enum es lo que se persiste en `user_roles.role` y lo que exige el
 * middleware `role`. Cada rol declara el conjunto de habilidades (abilities) que se
 * graban en el token al iniciar sesión, de modo que la autorización por endpoint se
 * resuelve con `tokenCan()` sin consultar la base en cada request.
 */
enum Role: string
{
    case Chofer = 'chofer';
    case Preparador = 'preparador';
    case Tienda = 'tienda';
    case Transportista = 'transportista';
    case Supervisor = 'supervisor';
    case Admin = 'admin';

    /**
     * Etiqueta legible en inglés (para el admin Filament y respuestas de la API).
     */
    public function label(): string
    {
        return match ($this) {
            self::Chofer => 'Driver',
            self::Preparador => 'Picker',
            self::Tienda => 'Store',
            self::Transportista => 'Carrier',
            self::Supervisor => 'Supervisor',
            self::Admin => 'Administrator',
        };
    }

    /**
     * Habilidades concedidas por el rol. Admin recibe el comodín '*'.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        return match ($this) {
            // Chofer: carga, ruta, descarga, POD, fallidas y GPS en vivo.
            self::Chofer => [
                Ability::SHIPMENTS_READ,
                Ability::PLANNING_READ,
                Ability::EXECUTION_READ,
                Ability::EXECUTION_WRITE,
                Ability::TRACKING_READ,
                Ability::TRACKING_WRITE,
            ],
            // Preparador: prepara pedidos por carriles (escaneos de preparación).
            self::Preparador => [
                Ability::SHIPMENTS_READ,
                Ability::PLANNING_READ,
                Ability::EXECUTION_READ,
                Ability::EXECUTION_WRITE,
            ],
            // Tienda: recepción en nodo, consulta de pedidos y seguimiento.
            self::Tienda => [
                Ability::SHIPMENTS_READ,
                Ability::NETWORK_READ,
                Ability::EXECUTION_READ,
                Ability::EXECUTION_WRITE,
                Ability::TRACKING_READ,
            ],
            // Transportista tercero: estados, documentos y seguimiento de sus envíos.
            self::Transportista => [
                Ability::CARRIERS_READ,
                Ability::CARRIERS_WRITE,
                Ability::EXECUTION_READ,
                Ability::TRACKING_READ,
                Ability::TRACKING_WRITE,
            ],
            // Supervisor: lectura amplia de la operación + planificación.
            self::Supervisor => [
                Ability::NETWORK_READ,
                Ability::FLEET_READ,
                Ability::SHIPMENTS_READ,
                Ability::PLANNING_READ,
                Ability::PLANNING_WRITE,
                Ability::EXECUTION_READ,
                Ability::TRACKING_READ,
                Ability::NOTIFICATIONS_READ,
                Ability::CAPACITY_READ,
                Ability::PROMISE_READ,
                Ability::CARRIERS_READ,
            ],
            // Admin: acceso total.
            self::Admin => [Ability::WILDCARD],
        };
    }

    /**
     * Opciones para selects del admin: valor => etiqueta en inglés.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];
        foreach (self::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }
}
