<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\Service;

use DOMDocument;
use DOMElement;
use DOMNodeList;
use Logistics\Coverage\Domain\Exception\CoverageException;
use Logistics\Coverage\Domain\ValueObject\Polygon;
use Logistics\Coverage\Domain\ValueObject\ServiceDays;
use Logistics\Coverage\Domain\ValueObject\ZoneRules;

/**
 * Servicio de dominio que traduce un documento KML (el mapa de cobertura actual exportado desde
 * Google Earth / Maps) a una lista de ParsedZone. Es PHP puro: usa DOMDocument (núcleo de PHP), sin
 * Laravel ni persistencia; el id de cada zona lo asigna el caso de uso, no el archivo de entrada.
 *
 * Convenciones de cada <Placemark>:
 *  - <name>: nombre de la zona (obligatorio).
 *  - <Polygon><outerBoundaryIs><LinearRing><coordinates>: anillo exterior. KML lista las posiciones
 *    como "lng,lat[,alt]" separadas por espacios/saltos de línea; se toma solo (lng, lat).
 *  - <ExtendedData><Data name="..."><value>: reglas opcionales (node, services, baseMinutes,
 *    productLimits, serviceDays, timeWindows) en forma de texto; se parsean best-effort.
 *
 * KML no es idempotente por sí mismo (no trae ids): cada importación crea zonas nuevas, igual que el
 * importador GeoJSON. La idempotencia, si se requiere, la resuelve el caso de uso.
 */
final class KmlCoverageParser
{
    private const string KML_NS = 'http://www.opengis.net/kml/2.2';

    /**
     * @return array<int, ParsedZone>
     */
    public function parse(string $kml): array
    {
        $kml = trim($kml);
        if ($kml === '') {
            throw CoverageException::because('el documento KML está vacío');
        }

        $document = new DOMDocument;
        // Se silencian los warnings de libxml y se inspecciona el resultado para lanzar un error de
        // dominio claro en lugar de dejar escapar un warning del parser.
        $previous = libxml_use_internal_errors(true);
        $loaded = $document->loadXML($kml);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($loaded === false) {
            throw CoverageException::because('el documento KML no es XML válido');
        }

        $placemarks = $this->placemarks($document);
        if ($placemarks->length === 0) {
            throw CoverageException::because('el KML no contiene ningún <Placemark>');
        }

        $zones = [];
        foreach ($placemarks as $placemark) {
            if ($placemark instanceof DOMElement) {
                $zones[] = $this->parsePlacemark($placemark);
            }
        }

        return $zones;
    }

    /**
     * @return DOMNodeList<\DOMNode>
     */
    private function placemarks(DOMDocument $document): DOMNodeList
    {
        // Se intenta primero con el namespace oficial de KML; si el documento viene sin namespace
        // (exports "sucios" habituales), se cae al nombre de etiqueta local.
        $namespaced = $document->getElementsByTagNameNS(self::KML_NS, 'Placemark');
        if ($namespaced->length > 0) {
            return $namespaced;
        }

        return $document->getElementsByTagName('Placemark');
    }

    private function parsePlacemark(DOMElement $placemark): ParsedZone
    {
        $name = $this->firstText($placemark, 'name');
        if ($name === null || $name === '') {
            throw CoverageException::because('cada <Placemark> necesita un <name>');
        }

        return new ParsedZone(
            $name,
            $this->parsePolygon($placemark),
            $this->parseRules($placemark),
        );
    }

    private function parsePolygon(DOMElement $placemark): Polygon
    {
        $coordinates = $this->firstText($placemark, 'coordinates');
        if ($coordinates === null || trim($coordinates) === '') {
            $name = $this->firstText($placemark, 'name') ?? '';
            throw CoverageException::because("el Placemark \"{$name}\" no tiene <coordinates>");
        }

        $ring = [];
        foreach (preg_split('/\s+/', trim($coordinates)) ?: [] as $tuple) {
            if ($tuple === '') {
                continue;
            }
            $parts = explode(',', $tuple);
            if (count($parts) < 2 || !is_numeric($parts[0]) || !is_numeric($parts[1])) {
                throw CoverageException::because("posición KML inválida (se espera lng,lat): {$tuple}");
            }
            // KML usa el orden lng,lat[,alt]; se descarta la altitud.
            $ring[] = [(float) $parts[0], (float) $parts[1]];
        }

        // KML no exige que el anillo esté cerrado; Polygon::fromRing sí. Se cierra si hace falta.
        if ($ring !== []) {
            $first = $ring[array_key_first($ring)];
            $last = $ring[array_key_last($ring)];
            if ($first[0] !== $last[0] || $first[1] !== $last[1]) {
                $ring[] = $first;
            }
        }

        return Polygon::fromRing($ring);
    }

    private function parseRules(DOMElement $placemark): ZoneRules
    {
        $data = $this->extendedData($placemark);

        $node = $data['node'] ?? null;
        $nodeId = $node !== null && $node !== '' ? $node : null;

        $services = [];
        if (isset($data['services']) && $data['services'] !== '') {
            foreach (explode(',', $data['services']) as $service) {
                $service = trim($service);
                if ($service !== '') {
                    $services[] = $service;
                }
            }
        }

        $baseMinutes = isset($data['baseMinutes']) && is_numeric($data['baseMinutes'])
            ? (int) $data['baseMinutes']
            : 0;

        $serviceDays = [];
        if (isset($data['serviceDays']) && $data['serviceDays'] !== '') {
            foreach (explode(',', $data['serviceDays']) as $day) {
                $day = trim($day);
                if (is_numeric($day)) {
                    $serviceDays[] = (int) $day;
                }
            }
        }
        $days = $serviceDays === [] ? ServiceDays::everyday() : ServiceDays::of(...$serviceDays);

        return ZoneRules::define($nodeId, $services, $baseMinutes, [], [], $days);
    }

    /**
     * Lee los pares <Data name="..."><value>...</value></Data> y <SimpleData name="...">...</SimpleData>
     * de un Placemark a un mapa clave→texto.
     *
     * @return array<string, string>
     */
    private function extendedData(DOMElement $placemark): array
    {
        $out = [];

        foreach ($placemark->getElementsByTagName('Data') as $dataNode) {
            if (!$dataNode instanceof DOMElement) {
                continue;
            }
            $key = $dataNode->getAttribute('name');
            if ($key === '') {
                continue;
            }
            $value = $this->firstText($dataNode, 'value');
            if ($value !== null) {
                $out[$key] = trim($value);
            }
        }

        foreach ($placemark->getElementsByTagName('SimpleData') as $simple) {
            if (!$simple instanceof DOMElement) {
                continue;
            }
            $key = $simple->getAttribute('name');
            if ($key !== '') {
                $out[$key] = trim($simple->textContent);
            }
        }

        return $out;
    }

    /**
     * Texto del primer descendiente con el nombre local dado (tolerante a namespace).
     */
    private function firstText(DOMElement $element, string $localName): ?string
    {
        $namespaced = $element->getElementsByTagNameNS(self::KML_NS, $localName);
        if ($namespaced->length > 0) {
            return $namespaced->item(0)?->textContent;
        }

        $plain = $element->getElementsByTagName($localName);

        return $plain->length > 0 ? $plain->item(0)?->textContent : null;
    }
}
