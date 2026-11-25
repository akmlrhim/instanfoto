<?php

declare(strict_types=1);

namespace InstanFoto;

use GdImage;

final class Images
{
    public static function isJpeg(string $buffer): bool
    {
        return strlen($buffer) >= 3
            && $buffer[0] === "\xFF"
            && $buffer[1] === "\xD8"
            && $buffer[2] === "\xFF";
    }

    public static function process(Config $config, string $buffer): array
    {
        $info = @getimagesizefromstring($buffer);

        if ($info === false || $info[2] !== IMAGETYPE_JPEG || $info[0] < 1 || $info[1] < 1) {
            throw new HttpError(415, 'Jenis file tidak didukung');
        }

        if ($info[0] > $config->maxImageEdge || $info[1] > $config->maxImageEdge) {
            throw new HttpError(422, 'Dimensi foto terlalu besar');
        }

        $image = @imagecreatefromstring($buffer);

        if (!$image instanceof GdImage) {
            throw new HttpError(415, 'Jenis file tidak didukung');
        }

        $image = self::applyOrientation($image, self::orientation($buffer));

        try {
            $web = self::encode($image, $config->webEdge, $config->webEdge, 85);
            $thumb = self::encode($image, $config->thumbWidth, null, 80);
        } finally {
            imagedestroy($image);
        }

        return ['web' => $web, 'thumb' => $thumb];
    }

    private static function orientation(string $buffer): int
    {
        $temporary = tempnam(sys_get_temp_dir(), 'instanfoto');

        if ($temporary === false) {
            return 1;
        }

        try {
            if (file_put_contents($temporary, $buffer) === false) {
                return 1;
            }

            $exif = @exif_read_data($temporary);
        } finally {
            @unlink($temporary);
        }

        $value = is_array($exif) && isset($exif['Orientation']) ? (int) $exif['Orientation'] : 1;

        return $value >= 1 && $value <= 8 ? $value : 1;
    }

    private static function applyOrientation(GdImage $image, int $orientation): GdImage
    {
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                return $image;
            case 3:
                return self::rotate($image, 180);
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                return $image;
            case 5:
                $rotated = self::rotate($image, -90);
                imageflip($rotated, IMG_FLIP_HORIZONTAL);
                return $rotated;
            case 6:
                return self::rotate($image, -90);
            case 7:
                $rotated = self::rotate($image, 90);
                imageflip($rotated, IMG_FLIP_HORIZONTAL);
                return $rotated;
            case 8:
                return self::rotate($image, 90);
            default:
                return $image;
        }
    }

    private static function rotate(GdImage $image, int $degrees): GdImage
    {
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        $rotated = imagerotate($image, $degrees, $transparent);

        if ($rotated instanceof GdImage) {
            imagealphablending($rotated, false);
            imagesavealpha($rotated, true);
            imagedestroy($image);

            return $rotated;
        }

        return $image;
    }

    private static function encode(GdImage $image, int $maxWidth, ?int $maxHeight, int $quality): string
    {
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min($maxWidth / $width, $maxHeight === null ? INF : $maxHeight / $height, 1.0);
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));
        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        if (!$canvas instanceof GdImage) {
            throw new HttpError(422, 'Foto tidak dapat diproses');
        }

        imagecopyresampled($canvas, $image, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        ob_start();
        imagejpeg($canvas, null, $quality);
        $encoded = ob_get_clean();
        imagedestroy($canvas);

        if (!is_string($encoded) || $encoded === '') {
            throw new HttpError(422, 'Foto tidak dapat diproses');
        }

        return $encoded;
    }
}