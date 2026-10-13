<?php

declare(strict_types=1);

namespace Logistics\Shipments\Infrastructure\Ingestion;

use Logistics\Shipments\Application\Command\ReceiveShipment\ReceiveShipment;
use Logistics\Shipments\Application\Command\ReceiveShipment\ShipmentLineInput;
use Logistics\Shipments\Application\Contract\ShipmentSpreadsheetParser;
use Logistics\Shipments\Application\Ingestion\ParsedShipmentRow;
use Logistics\Shipments\Domain\ValueObject\OrderSource;

/**
 * Parser real de la plantilla de carga de pedidos en CSV (formato F0-SRC-03), con la librería estándar
 * de PHP (sin dependencias externas: un Excel se exporta a CSV UTF-8).
 *
 * Formato: una fila de encabezado y luego una fila por LÍNEA de pedido. Las filas con el mismo par
 * (source, external_id) se agrupan en un mismo pedido con varias líneas. Columnas esperadas:
 *
 *   source, external_id, customer_name, address_text, address_reference, lat, lng, sku, product_name, quantity
 *
 * Columnas OPCIONALES por línea (para mostrar productos en las notificaciones): image_url, price. Si no
 * vienen, la línea queda sin imagen ni precio, sin invalidar la fila.
 *
 * Nunca lanza por una fila mala: la marca inválida con su número de fila y sigue, para reportar todos
 * los errores del archivo de una sola pasada.
 */
final class CsvShipmentSpreadsheetParser implements ShipmentSpreadsheetParser
{
    /** Columnas obligatorias que deben existir en el encabezado. */
    private const REQUIRED_COLUMNS = [
        'source', 'external_id', 'customer_name', 'address_text', 'sku', 'product_name', 'quantity',
    ];

    public function parse(string $contents): array
    {
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            return [ParsedShipmentRow::invalid(0, 'no se pudo abrir el contenido del archivo')];
        }

        fwrite($stream, $this->stripBom($contents));
        rewind($stream);

        // escape: '' sigue el estándar CSV (sin carácter de escape especial) y evita la deprecación de
        // PHP 8.3+ por el valor por defecto de $escape, que va a cambiar.
        $header = fgetcsv($stream, escape: '');
        if (!is_array($header)) {
            fclose($stream);

            return [ParsedShipmentRow::invalid(0, 'el archivo no tiene encabezado')];
        }

        /** @var array<string, int> $columns mapa nombre de columna → índice */
        $columns = [];
        foreach ($header as $index => $name) {
            if (is_string($name)) {
                $columns[strtolower(trim($name))] = $index;
            }
        }

        $missing = array_values(array_diff(self::REQUIRED_COLUMNS, array_keys($columns)));
        if ($missing !== []) {
            fclose($stream);

            return [ParsedShipmentRow::invalid(0, 'faltan columnas obligatorias: '.implode(', ', $missing))];
        }

        $errors = [];
        /** @var array<string, array{row: int, source: string, external_id: string, customer_name: string, address_text: string, address_reference: ?string, lat: ?float, lng: ?float, lines: list<ShipmentLineInput>}> $orders */
        $orders = [];

        $lineNumber = 1; // la fila 1 es el encabezado
        while (($record = fgetcsv($stream, escape: '')) !== false) {
            $lineNumber++;
            if ($record === [null]) {
                continue; // fila en blanco
            }

            $error = $this->validateRow($record, $columns);
            if ($error !== null) {
                $errors[] = ParsedShipmentRow::invalid($lineNumber, $error);

                continue;
            }

            $source = strtoupper(trim($this->cell($record, $columns, 'source')));
            $externalId = trim($this->cell($record, $columns, 'external_id'));
            $key = $source.'|'.$externalId;

            if (!isset($orders[$key])) {
                $orders[$key] = [
                    'row' => $lineNumber,
                    'source' => $source,
                    'external_id' => $externalId,
                    'customer_name' => trim($this->cell($record, $columns, 'customer_name')),
                    'address_text' => trim($this->cell($record, $columns, 'address_text')),
                    'address_reference' => $this->optionalCell($record, $columns, 'address_reference'),
                    'lat' => $this->floatCell($record, $columns, 'lat'),
                    'lng' => $this->floatCell($record, $columns, 'lng'),
                    'lines' => [],
                ];
            }

            $orders[$key]['lines'][] = new ShipmentLineInput(
                trim($this->cell($record, $columns, 'sku')),
                trim($this->cell($record, $columns, 'product_name')),
                (int) trim($this->cell($record, $columns, 'quantity')),
                $this->optionalCell($record, $columns, 'image_url'),
                $this->floatCell($record, $columns, 'price'),
            );
        }

        fclose($stream);

        $rows = $errors;
        foreach ($orders as $order) {
            $rows[] = ParsedShipmentRow::valid($order['row'], new ReceiveShipment(
                source: $order['source'],
                externalId: $order['external_id'],
                customerName: $order['customer_name'],
                addressText: $order['address_text'],
                addressReference: $order['address_reference'],
                lat: $order['lat'],
                lng: $order['lng'],
                lines: $order['lines'],
            ));
        }

        return $rows;
    }

    /**
     * Valida los campos de una fila; devuelve el motivo del primer problema o null si está bien.
     *
     * @param array<int, string|null> $record
     * @param array<string, int>      $columns
     */
    private function validateRow(array $record, array $columns): ?string
    {
        $source = strtoupper(trim($this->cell($record, $columns, 'source')));
        if ($source === '') {
            return 'source vacío';
        }
        if (OrderSource::tryFrom($source) === null) {
            return "source inválido: {$source}";
        }
        if (trim($this->cell($record, $columns, 'external_id')) === '') {
            return 'external_id vacío';
        }
        if (trim($this->cell($record, $columns, 'customer_name')) === '') {
            return 'customer_name vacío';
        }
        if (trim($this->cell($record, $columns, 'address_text')) === '') {
            return 'address_text vacío';
        }
        if (trim($this->cell($record, $columns, 'sku')) === '') {
            return 'sku vacío';
        }
        if (trim($this->cell($record, $columns, 'product_name')) === '') {
            return 'product_name vacío';
        }

        $quantity = trim($this->cell($record, $columns, 'quantity'));
        if (!ctype_digit($quantity) || (int) $quantity < 1) {
            return "quantity inválida: {$quantity}";
        }

        $lat = $this->rawCell($record, $columns, 'lat');
        $lng = $this->rawCell($record, $columns, 'lng');
        if ($lat !== '' && !is_numeric($lat)) {
            return "lat inválida: {$lat}";
        }
        if ($lng !== '' && !is_numeric($lng)) {
            return "lng inválida: {$lng}";
        }

        // Precio OPCIONAL: si viene, debe ser numérico y no negativo.
        $price = $this->rawCell($record, $columns, 'price');
        if ($price !== '' && (!is_numeric($price) || (float) $price < 0.0)) {
            return "price inválido: {$price}";
        }

        return null;
    }

    /**
     * @param array<int, string|null> $record
     * @param array<string, int>      $columns
     */
    private function cell(array $record, array $columns, string $column): string
    {
        if (!isset($columns[$column])) {
            return '';
        }
        $value = $record[$columns[$column]] ?? null;

        return is_string($value) ? $value : '';
    }

    /**
     * Valor crudo (sin trim) de una columna, para distinguir "ausente" de "con espacios".
     *
     * @param array<int, string|null> $record
     * @param array<string, int>      $columns
     */
    private function rawCell(array $record, array $columns, string $column): string
    {
        return trim($this->cell($record, $columns, $column));
    }

    /**
     * @param array<int, string|null> $record
     * @param array<string, int>      $columns
     */
    private function optionalCell(array $record, array $columns, string $column): ?string
    {
        $value = trim($this->cell($record, $columns, $column));

        return $value === '' ? null : $value;
    }

    /**
     * @param array<int, string|null> $record
     * @param array<string, int>      $columns
     */
    private function floatCell(array $record, array $columns, string $column): ?float
    {
        $value = trim($this->cell($record, $columns, $column));

        return $value === '' || !is_numeric($value) ? null : (float) $value;
    }

    /**
     * Quita el BOM UTF-8 que agregan Excel y otros exportadores al inicio del archivo.
     */
    private function stripBom(string $contents): string
    {
        if (str_starts_with($contents, "\xEF\xBB\xBF")) {
            return substr($contents, 3);
        }

        return $contents;
    }
}
