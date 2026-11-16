<?php

declare(strict_types=1);

namespace Logistics\Tracking\Infrastructure\Realtime;

use Illuminate\Support\Facades\DB;
use Logistics\Shared\Presentation\Realtime\RealtimeChannels;
use stdClass;
use Throwable;

/**
 * Resuelve, para la señal `tracking.updated`, el estado VIGENTE del pedido, la referencia de su orden y los
 * códigos de ciudad (departamento) a los que corresponde avisar. Solo lectura, por el query builder y con
 * consultas baratas por índice; corre en la cola, nunca en un request.
 *
 * El estado y la referencia salen del read model propio (`tracking_shipments`); la ciudad del pedido se lee
 * del envío en `shipments` (departamento de destino y de origen), en solo lectura y sin acoplar el dominio
 * de otro contexto (igual que los demás lectores cross-context del package).
 *
 * @phpstan-type TrackingSnapshot array{status: string, order_reference: string|null, cities: list<string>}
 */
final class TrackingSignalReader
{
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * Foto del seguimiento del envío para la señal, o null si ya no existe (el seguimiento se borró o el id
     * no tiene forma de UUID: comparar otro texto contra una columna `uuid` hace fallar la consulta en
     * PostgreSQL).
     *
     * @return TrackingSnapshot|null
     */
    public function resolve(string $shipmentId): ?array
    {
        $id = self::uuid($shipmentId);
        if ($id === null) {
            return null;
        }

        $row = DB::table('tracking_shipments')->where('shipment_id', $id)->first(['status', 'order_reference']);
        $status = $row instanceof stdClass && is_string($row->status ?? null) ? trim($row->status) : '';
        if ($status === '') {
            return null;
        }

        $orderReference = $row instanceof stdClass && is_string($row->order_reference ?? null) ? trim($row->order_reference) : '';

        return [
            'status' => $status,
            'order_reference' => $orderReference === '' ? null : $orderReference,
            'cities' => $this->cities($id),
        ];
    }

    /**
     * Códigos de ciudad (departamento) del envío, el de destino primero: son los que arman los canales del
     * tema por ciudad. Tolerante: si el envío no se puede leer o no deja un código válido, va vacío (se
     * avisa solo al canal general, nunca se esconde el cambio).
     *
     * @return list<string>
     */
    private function cities(string $shipmentId): array
    {
        try {
            $row = DB::table('shipments')
                ->where('id', $shipmentId)
                ->first(['destination_department', 'origin_department']);
        } catch (Throwable) {
            return [];
        }

        if (!$row instanceof stdClass) {
            return [];
        }

        $cities = [];
        foreach ([$row->destination_department ?? null, $row->origin_department ?? null] as $value) {
            $code = RealtimeChannels::cityCode(is_string($value) ? $value : null);
            if ($code !== null && !in_array($code, $cities, true)) {
                $cities[] = $code;
            }
        }

        return $cities;
    }

    private static function uuid(string $value): ?string
    {
        return preg_match(self::UUID_PATTERN, $value) === 1 ? strtolower($value) : null;
    }
}
