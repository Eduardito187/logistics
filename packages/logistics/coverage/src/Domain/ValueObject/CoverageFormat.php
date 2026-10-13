<?php

declare(strict_types=1);

namespace Logistics\Coverage\Domain\ValueObject;

use Logistics\Coverage\Domain\Exception\CoverageException;

/**
 * Formato del documento de cobertura que se importa: KML (XML de Google Earth/Maps) o GeoJSON.
 *
 * Es PHP puro: el dominio decide el formato a partir del contenido o la extensión, sin conocer el
 * mecanismo de subida (eso es Presentation). El handler de importación elige el parser según este VO.
 */
enum CoverageFormat: string
{
    case Kml = 'kml';
    case GeoJson = 'geojson';

    /**
     * Detecta el formato por el contenido crudo: un documento que empieza por "<" es XML (KML);
     * cualquier otro inicio se trata como JSON (GeoJSON). Lanza si el contenido está vacío.
     */
    public static function detect(string $content): self
    {
        $trimmed = ltrim($content);
        if ($trimmed === '') {
            throw CoverageException::because('el documento de cobertura está vacío');
        }

        return $trimmed[0] === '<' ? self::Kml : self::GeoJson;
    }

    /**
     * Deduce el formato por la extensión del archivo subido (.kml / .geojson / .json). Devuelve null
     * si la extensión no es reconocida, para que el llamador caiga en la detección por contenido.
     */
    public static function fromExtension(string $extension): ?self
    {
        return match (strtolower(ltrim($extension, '.'))) {
            'kml' => self::Kml,
            'geojson', 'json' => self::GeoJson,
            default => null,
        };
    }
}
