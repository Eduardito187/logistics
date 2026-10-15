<?php

declare(strict_types=1);

namespace Logistics\Notifications\Domain\Contract;

use Logistics\Notifications\Domain\ValueObject\MessageType;

/**
 * Puerto de dominio: dado el id de un envío y el tipo de mensaje, arma el mapa de variables con las
 * que se interpolan las plantillas de notificación ({{order}}, {{customer}}, {{address}}, {{eta}},
 * {{stops}}, …).
 *
 * Los valores salen de los datos reales del envío (referencia humana del pedido, nombre del cliente,
 * dirección) y del tracking (paradas restantes, hora estimada) cuando están disponibles. Es solo
 * lectura y tolerante: si un dato no existe, la clave se omite o queda vacía, nunca rompe el envío.
 *
 * La implementación vive en Infrastructure (lee las tablas de otros contextos con el query builder);
 * el dominio solo conoce el contrato, igual que ProductImageResolver.
 */
interface NotificationVariableProvider
{
    /**
     * Variables de interpolación del envío, keyed por nombre de marcador (sin llaves).
     *
     * @return array<string, string> Mapa clave → valor (vacío si no se pudo resolver nada).
     */
    public function variablesFor(string $shipmentId, MessageType $messageType): array;
}
