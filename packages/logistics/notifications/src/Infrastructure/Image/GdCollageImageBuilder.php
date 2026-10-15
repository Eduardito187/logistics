<?php

declare(strict_types=1);

namespace Logistics\Notifications\Infrastructure\Image;

use GdImage;
use Illuminate\Support\Facades\Http;
use Logistics\Notifications\Domain\Contract\CollageImageBuilder;
use Logistics\Notifications\Domain\ValueObject\ProductThumbnail;
use Throwable;

/**
 * Arma el collage de productos con la extensión GD (P1-NOT: contenido rico de push). Regla de
 * composición pedida por el dueño: hasta 4 productos en una grilla 2x2; con más de 4, se muestran los
 * primeros 3 y una cuarta celda "+N" con el resto.
 *
 * Degrada sin romper: si GD no está cargado, si no hay imágenes utilizables o si todas las descargas
 * fallan, devuelve null y el canal manda el push sin imagen. Corre en el relay del outbox (asíncrono);
 * las descargas tienen timeout corto para no colgar un worker.
 */
final class GdCollageImageBuilder implements CollageImageBuilder
{
    private const CANVAS = 600;          // Lado del lienzo cuadrado del collage, en px.

    private const MAX_CELLS = 4;         // Máximo de celdas visibles (grilla 2x2).

    public function __construct(
        private readonly int $timeout = 5,
    ) {}

    public function build(array $products): ?string
    {
        if (!extension_loaded('gd') || !function_exists('imagecreatetruecolor')) {
            return null;
        }

        $withImage = array_values(array_filter(
            $products,
            static fn (ProductThumbnail $p): bool => $p->imageUrl !== null,
        ));
        if ($withImage === []) {
            return null;
        }

        $total = count($withImage);
        $overflow = $total > self::MAX_CELLS;

        // Con overflow mostramos 3 imágenes + 1 celda "+N"; si no, hasta 4 imágenes.
        $imageSlots = $overflow ? self::MAX_CELLS - 1 : min($total, self::MAX_CELLS);

        $tiles = [];
        for ($i = 0; $i < $imageSlots; $i++) {
            $tile = $this->loadImage($withImage[$i]->imageUrl);
            if ($tile !== null) {
                $tiles[] = $tile;
            }
        }
        if ($tiles === []) {
            return null;
        }

        $cellCount = $overflow ? count($tiles) + 1 : count($tiles);
        $extra = $overflow ? $total - count($tiles) : 0;

        $canvas = imagecreatetruecolor(self::CANVAS, self::CANVAS);
        if ($canvas === false) {
            $this->destroyAll($tiles);

            return null;
        }

        $bg = (int) imagecolorallocate($canvas, 240, 240, 240);
        imagefilledrectangle($canvas, 0, 0, self::CANVAS, self::CANVAS, $bg);

        // Una o varias celdas: 1 → lienzo completo; 2 → dos columnas; 3-4 → grilla 2x2.
        [$cols, $rows] = $this->grid($cellCount);
        $cellW = intdiv(self::CANVAS, $cols);
        $cellH = intdiv(self::CANVAS, $rows);

        $placed = 0;
        foreach ($tiles as $tile) {
            $this->placeTile($canvas, $tile, $placed, $cols, $cellW, $cellH);
            imagedestroy($tile);
            $placed++;
        }

        if ($overflow) {
            $this->placeOverflowCell($canvas, $placed, $cols, $cellW, $cellH, $extra);
        }

        $png = $this->encode($canvas);
        imagedestroy($canvas);

        return $png;
    }

    /**
     * Descarga una imagen remota y la decodifica a un recurso GD, o null si falla.
     */
    private function loadImage(?string $url): ?GdImage
    {
        if ($url === null) {
            return null;
        }

        try {
            $response = Http::timeout($this->timeout)->get($url);
            if ($response->failed()) {
                return null;
            }
            $image = @imagecreatefromstring($response->body());

            return $image === false ? null : $image;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Copia una miniatura recortada "cover" dentro de su celda de la grilla.
     */
    private function placeTile(GdImage $canvas, GdImage $tile, int $index, int $cols, int $cellW, int $cellH): void
    {
        $col = $index % $cols;
        $row = intdiv($index, $cols);
        $dstX = $col * $cellW;
        $dstY = $row * $cellH;

        $srcW = imagesx($tile);
        $srcH = imagesy($tile);

        // Recorte "cover": toma el cuadro central que llena la celda sin deformar.
        $scale = max($cellW / $srcW, $cellH / $srcH);
        $cropW = (int) round($cellW / $scale);
        $cropH = (int) round($cellH / $scale);
        $cropX = (int) round(($srcW - $cropW) / 2);
        $cropY = (int) round(($srcH - $cropH) / 2);

        imagecopyresampled($canvas, $tile, $dstX, $dstY, $cropX, $cropY, $cellW, $cellH, $cropW, $cropH);
    }

    /**
     * Pinta la celda "+N" (productos que no entraron en el collage).
     */
    private function placeOverflowCell(GdImage $canvas, int $index, int $cols, int $cellW, int $cellH, int $extra): void
    {
        $col = $index % $cols;
        $row = intdiv($index, $cols);
        $dstX = $col * $cellW;
        $dstY = $row * $cellH;

        $fill = (int) imagecolorallocate($canvas, 226, 35, 26); // Rojo Dismac.
        imagefilledrectangle($canvas, $dstX, $dstY, $dstX + $cellW, $dstY + $cellH, $fill);

        $white = (int) imagecolorallocate($canvas, 255, 255, 255);
        $label = '+'.$extra;
        $font = 5;
        $textW = imagefontwidth($font) * strlen($label);
        $textH = imagefontheight($font);
        $textX = $dstX + intdiv($cellW - $textW, 2);
        $textY = $dstY + intdiv($cellH - $textH, 2);
        imagestring($canvas, $font, $textX, $textY, $label, $white);
    }

    /**
     * Columnas y filas de la grilla según el número de celdas.
     *
     * @return array{int, int}
     */
    private function grid(int $cells): array
    {
        return match (true) {
            $cells <= 1 => [1, 1],
            $cells === 2 => [2, 1],
            default => [2, 2],
        };
    }

    private function encode(GdImage $canvas): ?string
    {
        ob_start();
        $ok = imagepng($canvas);
        $bytes = (string) ob_get_clean();

        return $ok && $bytes !== '' ? $bytes : null;
    }

    /**
     * @param list<GdImage> $tiles
     */
    private function destroyAll(array $tiles): void
    {
        foreach ($tiles as $tile) {
            imagedestroy($tile);
        }
    }
}
