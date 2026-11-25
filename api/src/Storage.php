<?php

declare(strict_types=1);

namespace InstanFoto;

final class Storage
{
    public static function savePhotoFiles(
        Config $config,
        string $sessionId,
        string $filename,
        string $web,
        string $thumb,
    ): array {
        Security::requireValidSessionId($sessionId);

        $directory = $config->uploadDir . '/' . $sessionId;

        if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new \RuntimeException('Folder unggahan tidak dapat dibuat.');
        }

        $webPath = $directory . '/' . $filename;
        $thumbPath = $directory . '/thumb_' . $filename;
        $written = [];

        try {
            foreach ([[$webPath, $web], [$thumbPath, $thumb]] as [$path, $bytes]) {
                $handle = @fopen($path, 'xb');

                if ($handle === false) {
                    throw new \RuntimeException('Foto tidak dapat disimpan.');
                }

                $written[] = $path;

                try {
                    $offset = 0;
                    $length = strlen($bytes);

                    while ($offset < $length) {
                        $chunk = fwrite($handle, substr($bytes, $offset));

                        if ($chunk === false || $chunk === 0) {
                            throw new \RuntimeException('Foto tidak dapat disimpan.');
                        }

                        $offset += $chunk;
                    }
                } finally {
                    fclose($handle);
                }
            }
        } catch (\Throwable $error) {
            foreach ($written as $path) {
                @unlink($path);
            }

            throw $error;
        }

        return [
            'web' => self::publicUrl($sessionId, $filename),
            'thumb' => self::publicUrl($sessionId, 'thumb_' . $filename),
        ];
    }

    public static function deletePhotoFiles(Config $config, string $sessionId, string $filename): void
    {
        $path = self::filePath($config, $sessionId, $filename);

        if ($path === null) {
            return;
        }

        @unlink($path);
        @unlink(dirname($path) . '/thumb_' . basename($path));
    }

    public static function deleteSessionFiles(Config $config, string $sessionId): void
    {
        if (preg_match(Security::SESSION_ID_PATTERN, $sessionId) !== 1) {
            return;
        }

        self::removeDirectory($config->uploadDir . '/' . $sessionId);
    }

    public static function filePath(Config $config, string $sessionId, string $filename): ?string
    {
        if (preg_match(Security::SESSION_ID_PATTERN, $sessionId) !== 1) {
            return null;
        }

        if (preg_match('/^(?:thumb_)?[a-z0-9]{16}\.jpg$/', $filename) !== 1) {
            return null;
        }

        $base = realpath($config->uploadDir);

        if ($base === false) {
            return null;
        }

        $path = realpath($base . '/' . $sessionId . '/' . $filename);

        if ($path === false || !str_starts_with($path, $base . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    private static function publicUrl(string $sessionId, string $filename): string
    {
        return '/api/uploads/' . $sessionId . '/' . $filename;
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }

        $items = new \FilesystemIterator($directory, \FilesystemIterator::SKIP_DOTS);

        foreach ($items as $item) {
            if ($item->isDir() && !$item->isLink()) {
                self::removeDirectory($item->getPathname());
                continue;
            }

            @unlink($item->getPathname());
        }

        @rmdir($directory);
    }
}