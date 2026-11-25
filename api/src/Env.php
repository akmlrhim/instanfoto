<?php

declare(strict_types=1);

namespace InstanFoto;

final class Env
{
    public static function load(string $file): void
    {
        if (!is_file($file)) {
            return;
        }

        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $separator = strpos($line, '=');

            if ($separator === false) {
                continue;
            }

            $key = trim(substr($line, 0, $separator));
            $value = trim(substr($line, $separator + 1));

            if ($key === '' || getenv($key) !== false) {
                continue;
            }

            if (strlen($value) >= 2) {
                $first = $value[0];
                $last = $value[strlen($value) - 1];

                if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                    $value = substr($value, 1, -1);
                }
            }

            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }

    public static function get(string $name, string $default = ''): string
    {
        $value = getenv($name);

        return $value === false ? $default : $value;
    }

    public static function number(string $name, int $fallback): int
    {
        $raw = self::get($name);

        if ($raw === '' || !is_numeric($raw)) {
            return $fallback;
        }

        $value = (int) $raw;

        return $value >= 0 ? $value : $fallback;
    }
}