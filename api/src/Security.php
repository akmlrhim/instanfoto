<?php

declare(strict_types=1);

namespace InstanFoto;

final class Security
{
    public const SESSION_ID_ALPHABET = 'abcdefghijkmnpqrstuvwxyz23456789';

    public const SESSION_ID_PATTERN = '/^[a-z0-9]{12}$/';

    private const CONTENT_SECURITY_POLICY = "default-src 'self'; "
        . "img-src 'self' data:; "
        . "script-src 'self'; "
        . "style-src 'self'; "
        . "base-uri 'none'; "
        . 'frame-ancestors \'none\'';

    public static function sendHeaders(): void
    {
        header_remove('X-Powered-By');
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: DENY');
        header('Referrer-Policy: no-referrer');
        header('X-Robots-Tag: noindex, nofollow');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        header('Content-Security-Policy: ' . self::CONTENT_SECURITY_POLICY);
    }

    public static function randomString(int $length): string
    {
        $max = strlen(self::SESSION_ID_ALPHABET) - 1;
        $result = '';

        for ($index = 0; $index < $length; $index += 1) {
            $result .= self::SESSION_ID_ALPHABET[random_int(0, $max)];
        }

        return $result;
    }

    public static function clientIp(Config $config): string
    {
        if ($config->trustProxy) {
            $forwarded = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';

            if ($forwarded !== '') {
                $first = trim(explode(',', $forwarded)[0]);

                if (filter_var($first, FILTER_VALIDATE_IP) !== false) {
                    return $first;
                }
            }
        }

        $remote = $_SERVER['REMOTE_ADDR'] ?? '';

        return $remote === '' ? 'unknown' : $remote;
    }

    public static function clientFingerprint(Config $config): string
    {
        return substr(hash('sha256', self::clientIp($config)), 0, 24);
    }

    public static function logEvent(string $method, string $path, int $status, string $note = ''): void
    {
        $fields = [
            gmdate('Y-m-d\TH:i:s\Z'),
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            $method . ' ' . $path,
            (string) $status,
        ];

        if ($note !== '') {
            $fields[] = $note;
        }

        error_log(implode("\t", $fields));
    }

    public static function enforceAlbumLimits(Config $config): void
    {
        $identity = self::clientFingerprint($config);

        self::hitRateLimit('album_min', 60, $config->albumPerMinute, $identity);
        self::hitRateLimit('album_burst', 10, $config->albumBurst, $identity);
    }

    public static function enforceWriteLimits(Config $config): void
    {
        $identity = self::clientFingerprint($config);

        self::hitRateLimit('write_min', 60, $config->writePerMinute, $identity);
        self::hitRateLimit('write_burst', 10, $config->writeBurst, $identity);
        self::checkAuthBan($config);
    }

    public static function checkAuthBan(Config $config): void
    {
        $windowSeconds = $config->authFailWindowMinutes * 60;
        $windowStart = intdiv(time(), $windowSeconds) * $windowSeconds;
        $statement = Database::query(
            'SELECT hits FROM rate_limits WHERE bucket = ? AND ip = ? AND window_start = ?',
            ['authfail', self::clientFingerprint($config), $windowStart],
        );
        $hits = (int) ($statement->fetchColumn() ?: 0);

        if ($hits >= $config->authFailMax) {
            throw new HttpError(429, 'Permintaan ditolak sementara');
        }
    }

    public static function requireApiKey(Config $config): void
    {
        $provided = $_SERVER['HTTP_X_API_KEY'] ?? '';

        if ($provided === '' || !hash_equals($config->apiKey, $provided)) {
            self::incrementBucket('authfail', $config->authFailWindowMinutes * 60, self::clientFingerprint($config));
            throw new HttpError(401, 'Kunci API tidak valid');
        }
    }

    public static function checkSessionCreationLimit(Config $config): void
    {
        $windowStart = intdiv(time(), 3600) * 3600;
        $keyId = substr(hash('sha256', $config->apiKey), 0, 16);
        $hits = self::incrementBucket('session_create', 3600, $keyId, $windowStart);

        if ($hits > $config->maxSessionsPerHour) {
            throw new HttpError(429, 'Terlalu banyak sesi dibuat');
        }
    }

    public static function requireValidSessionId(string $id): void
    {
        if (preg_match(self::SESSION_ID_PATTERN, $id) !== 1) {
            throw new HttpError(404, 'Album tidak ditemukan');
        }
    }

    private static function hitRateLimit(string $bucket, int $windowSeconds, int $limit, string $identity): void
    {
        if (self::incrementBucket($bucket, $windowSeconds, $identity) > $limit) {
            throw new HttpError(429, 'Terlalu banyak permintaan');
        }
    }

    private static function incrementBucket(
        string $bucket,
        int $windowSeconds,
        string $identity,
        ?int $windowStart = null,
    ): int {
        $windowStart ??= intdiv(time(), $windowSeconds) * $windowSeconds;

        Database::query(
            'INSERT INTO rate_limits (bucket, ip, window_start, hits)
             VALUES (?, ?, ?, 1)
             ON CONFLICT(bucket, ip, window_start) DO UPDATE SET hits = hits + 1',
            [$bucket, $identity, $windowStart],
        );

        $statement = Database::query(
            'SELECT hits FROM rate_limits WHERE bucket = ? AND ip = ? AND window_start = ?',
            [$bucket, $identity, $windowStart],
        );

        return (int) ($statement->fetchColumn() ?: 0);
    }
}