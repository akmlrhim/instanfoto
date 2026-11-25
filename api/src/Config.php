<?php

declare(strict_types=1);

namespace InstanFoto;

final class Config
{
    public function __construct(
        public readonly string $root,
        public readonly string $dataDir,
        public readonly string $uploadDir,
        public readonly string $databasePath,
        public readonly string $apiKey,
        public readonly string $baseUrl,
        public readonly string $cronSecret,
        public readonly bool $trustProxy,
        public readonly int $maxPhotosPerSession,
        public readonly int $maxSessionsPerHour,
        public readonly int $maxTotalMb,
        public readonly int $retentionDays,
        public readonly int $maxUploadBytes,
        public readonly int $maxImageEdge,
        public readonly int $webEdge,
        public readonly int $thumbWidth,
        public readonly int $authFailMax,
        public readonly int $authFailWindowMinutes,
        public readonly int $albumPerMinute,
        public readonly int $albumBurst,
        public readonly int $writePerMinute,
        public readonly int $writeBurst,
    ) {
    }

    public static function fromEnvironment(): self
    {
        $root = dirname(__DIR__, 2);
        $dataDir = self::absolute($root, Env::get('DATA_DIR', 'api/data'));

        return new self(
            root: $root,
            dataDir: $dataDir,
            uploadDir: $dataDir . '/uploads',
            databasePath: $dataDir . '/app.sqlite',
            apiKey: Env::get('API_KEY'),
            baseUrl: rtrim(Env::get('BASE_URL', 'http://127.0.0.1:5173'), '/'),
            cronSecret: Env::get('CRON_SECRET'),
            trustProxy: Env::get('TRUST_PROXY') === '1',
            maxPhotosPerSession: Env::number('MAX_PHOTOS_PER_SESSION', 500),
            maxSessionsPerHour: Env::number('MAX_SESSIONS_PER_HOUR', 60),
            maxTotalMb: Env::number('MAX_TOTAL_MB', 800),
            retentionDays: Env::number('RETENTION_DAYS', 30),
            maxUploadBytes: Env::number('MAX_UPLOAD_BYTES', 4 * 1024 * 1024),
            maxImageEdge: 4000,
            webEdge: 2000,
            thumbWidth: 400,
            authFailMax: 10,
            authFailWindowMinutes: 10,
            albumPerMinute: Env::number('RATE_ALBUM_PER_MIN', 60),
            albumBurst: Env::number('RATE_ALBUM_BURST', 30),
            writePerMinute: Env::number('RATE_WRITE_PER_MIN', 120),
            writeBurst: Env::number('RATE_WRITE_BURST', 40),
        );
    }

    public function assertRuntime(): void
    {
        if (strlen($this->apiKey) < 32) {
            throw new \RuntimeException(
                'API_KEY wajib diisi, minimal 32 karakter. Salin .env.example ke .env.',
            );
        }
    }

    private static function absolute(string $root, string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\\/]|\\/)/', $path) === 1) {
            return rtrim($path, '/\\');
        }

        return $root . '/' . trim($path, '/\\');
    }
}