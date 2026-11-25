<?php

declare(strict_types=1);

namespace InstanFoto;

final class App
{
    public static function run(): void
    {
        $config = Config::fromEnvironment();
        $method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = is_string($path) && $path !== '' ? $path : '/';
        $path = $path === '/' ? $path : rtrim($path, '/');

        try {
            Security::sendHeaders();
            $config->assertRuntime();
            Database::init($config);
            self::dispatch($config, $method, $path);
        } catch (HttpError $error) {
            Security::logEvent($method, $path, $error->status);
            self::respond($error->status, ['error' => $error->getMessage()]);
        } catch (\Throwable $error) {
            error_log(sprintf('[error] %s %s %s', $method, $path, $error->getMessage()));
            self::respond(500, ['error' => 'Terjadi kesalahan']);
        }
    }

    private static function dispatch(Config $config, string $method, string $path): void
    {
        if ($method === 'GET' && $path === '/api/health') {
            self::respond(200, ['ok' => true]);
            return;
        }

        if ($method === 'POST' && $path === '/api/sessions') {
            self::createSession($config, $method, $path);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/sessions/([^/]+)/photos$#', $path, $matches) === 1) {
            Security::requireValidSessionId($matches[1]);
            self::uploadPhoto($config, $method, $path, $matches[1]);
            return;
        }

        if ($method === 'POST' && preg_match('#^/api/sessions/([^/]+)/finish$#', $path, $matches) === 1) {
            Security::requireValidSessionId($matches[1]);
            self::finishSession($config, $method, $path, $matches[1]);
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/albums/([^/]+)$#', $path, $matches) === 1) {
            Security::requireValidSessionId($matches[1]);
            self::album($config, $method, $path, $matches[1]);
            return;
        }

        if ($method === 'DELETE' && preg_match('#^/api/sessions/([^/]+)$#', $path, $matches) === 1) {
            Security::requireValidSessionId($matches[1]);
            self::deleteSession($config, $method, $path, $matches[1]);
            return;
        }

        if ($method === 'GET' && preg_match('#^/api/uploads/([^/]+)/([^/]+)$#', $path, $matches) === 1) {
            self::serveUpload($config, $matches[1], $matches[2]);
            return;
        }

        if ($method === 'GET' && $path === '/api/cron/cleanup') {
            self::cronCleanup($config, $method, $path);
            return;
        }

        if (str_starts_with($path, '/api/')) {
            throw new HttpError(404, 'Halaman tidak ditemukan');
        }

        self::serveSpa();
    }

    private static function createSession(Config $config, string $method, string $path): void
    {
        Security::enforceWriteLimits($config);
        Security::requireApiKey($config);
        Security::checkSessionCreationLimit($config);

        $event = self::parseEvent(self::readJsonBody());
        $id = Security::randomString(12);

        Database::query('INSERT INTO sessions (id, event, finished) VALUES (?, ?, 0)', [$id, $event]);

        Security::logEvent($method, $path, 201, 'created');
        self::respond(201, ['id' => $id]);
    }

    private static function uploadPhoto(Config $config, string $method, string $path, string $sessionId): void
    {
        Security::enforceWriteLimits($config);
        Security::requireApiKey($config);

        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($contentLength > $config->maxUploadBytes + 65536) {
            throw new HttpError(413, 'File terlalu besar');
        }

        $session = Database::query('SELECT finished FROM sessions WHERE id = ?', [$sessionId])->fetch();

        if ($session === false) {
            throw new HttpError(404, 'Sesi tidak ditemukan');
        }

        if ((int) $session['finished'] === 1) {
            throw new HttpError(409, 'Sesi sudah selesai');
        }

        $count = Database::query(
            'SELECT COUNT(*) FROM photos WHERE session_id = ?',
            [$sessionId],
        )->fetchColumn();

        if ((int) $count >= $config->maxPhotosPerSession) {
            throw new HttpError(422, 'Batas foto per sesi terlampaui');
        }

        $used = Database::query('SELECT COALESCE(SUM(bytes), 0) FROM photos')->fetchColumn();

        if ((int) $used > $config->maxTotalMb * 1048576 * 0.9) {
            throw new HttpError(507, 'Penyimpanan penuh');
        }

        $file = $_FILES['photo'] ?? null;

        if (!is_array($file) || !isset($file['error'], $file['tmp_name']) || !is_string($file['tmp_name'])) {
            throw new HttpError(400, 'Foto tidak ditemukan');
        }

        if ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE) {
            throw new HttpError(413, 'File terlalu besar');
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new HttpError(400, 'Foto tidak ditemukan');
        }

        $buffer = file_get_contents($file['tmp_name']);

        if ($buffer === false) {
            throw new HttpError(400, 'Foto tidak ditemukan');
        }

        if (!Images::isJpeg($buffer)) {
            throw new HttpError(415, 'Jenis file tidak didukung');
        }

        $processed = Images::process($config, $buffer);
        $filename = Security::randomString(16) . '.jpg';
        $stored = Storage::savePhotoFiles(
            $config,
            $sessionId,
            $filename,
            $processed['web'],
            $processed['thumb'],
        );

        try {
            Database::query(
                'INSERT INTO photos (session_id, filename, web_url, thumb_url, bytes)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $sessionId,
                    $filename,
                    $stored['web'],
                    $stored['thumb'],
                    strlen($processed['web']) + strlen($processed['thumb']),
                ],
            );
        } catch (\Throwable $error) {
            Storage::deletePhotoFiles($config, $sessionId, $filename);
            throw $error;
        }

        Security::logEvent($method, $path, 201, 'uploaded');
        self::respond(201, ['ok' => true, 'file' => $filename]);
    }

    private static function finishSession(Config $config, string $method, string $path, string $sessionId): void
    {
        Security::enforceWriteLimits($config);
        Security::requireApiKey($config);

        $session = Database::query('SELECT id FROM sessions WHERE id = ?', [$sessionId])->fetch();

        if ($session === false) {
            throw new HttpError(404, 'Sesi tidak ditemukan');
        }

        Database::query('UPDATE sessions SET finished = 1 WHERE id = ?', [$sessionId]);

        Security::logEvent($method, $path, 200, 'finished');
        self::respond(200, ['url' => $config->baseUrl . '/a/' . $sessionId]);
    }

    private static function album(Config $config, string $method, string $path, string $sessionId): void
    {
        Security::enforceAlbumLimits($config);

        $session = Database::query('SELECT id FROM sessions WHERE id = ?', [$sessionId])->fetch();

        if ($session === false) {
            throw new HttpError(404, 'Album tidak ditemukan');
        }

        $photos = Database::query(
            'SELECT id, web_url, thumb_url FROM photos WHERE session_id = ? ORDER BY id ASC',
            [$sessionId],
        )->fetchAll();

        Security::logEvent($method, $path, 200, 'album ' . count($photos));
        self::respond(200, [
            'photos' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'web' => $row['web_url'],
                    'thumb' => $row['thumb_url'],
                ],
                $photos,
            ),
        ]);
    }

    private static function deleteSession(Config $config, string $method, string $path, string $sessionId): void
    {
        Security::enforceWriteLimits($config);
        Security::requireApiKey($config);

        $session = Database::query('SELECT id FROM sessions WHERE id = ?', [$sessionId])->fetch();

        if ($session === false) {
            throw new HttpError(404, 'Sesi tidak ditemukan');
        }

        Storage::deleteSessionFiles($config, $sessionId);
        Database::query('DELETE FROM photos WHERE session_id = ?', [$sessionId]);
        Database::query('DELETE FROM sessions WHERE id = ?', [$sessionId]);

        Security::logEvent($method, $path, 204, 'deleted');
        self::respond(204, []);
    }

    private static function cronCleanup(Config $config, string $method, string $path): void
    {
        $provided = self::authorizationHeader();
        $expected = 'Bearer ' . $config->cronSecret;
        $valid = $config->cronSecret !== '' && hash_equals($expected, $provided);

        if (!$valid) {
            throw new HttpError(401, 'Tidak diizinkan');
        }

        $result = Cleanup::run($config);

        Security::logEvent($method, $path, 200, 'cleanup ' . $result['deleted']);
        self::respond(200, $result);
    }

    private static function serveUpload(Config $config, string $sessionId, string $filename): void
    {
        $path = Storage::filePath($config, $sessionId, $filename);

        if ($path === null) {
            throw new HttpError(404, 'Halaman tidak ditemukan');
        }

        header('Content-Type: image/jpeg');
        header('Content-Length: ' . (string) filesize($path));
        header('Cache-Control: public, max-age=2592000, immutable');
        readfile($path);
    }

    private static function serveSpa(): void
    {
        $index = __DIR__ . '/../public/index.html';

        if (!is_file($index)) {
            throw new HttpError(404, 'Halaman tidak ditemukan');
        }

        header('Content-Type: text/html; charset=utf-8');
        readfile($index);
    }

    private static function readJsonBody(): array|string
    {
        $contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);

        if ($contentLength > 4096) {
            throw new HttpError(413, 'Permintaan terlalu besar');
        }

        $raw = file_get_contents('php://input');

        if ($raw === false || trim($raw) === '') {
            return '';
        }

        if (!str_starts_with(ltrim($raw), '{')) {
            throw new HttpError(400, 'JSON tidak valid');
        }

        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpError(400, 'JSON tidak valid');
        }

        if (!is_array($decoded)) {
            throw new HttpError(400, 'JSON tidak valid');
        }

        return $decoded;
    }

    private static function parseEvent(array|string $body): string
    {
        if ($body === '') {
            return '';
        }

        $event = $body['event'] ?? '';

        if (!is_string($event) || mb_strlen($event, 'UTF-8') > 100) {
            throw new HttpError(422, 'Nama acara tidak valid');
        }

        return $event;
    }

    private static function authorizationHeader(): string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if ($header !== '') {
            return $header;
        }

        if (function_exists('getallheaders')) {
            $headers = getallheaders();

            if (is_array($headers)) {
                foreach ($headers as $name => $value) {
                    if (strcasecmp($name, 'Authorization') === 0) {
                        return (string) $value;
                    }
                }
            }
        }

        return '';
    }

    private static function respond(int $status, array $payload): void
    {
        if (headers_sent()) {
            return;
        }

        http_response_code($status);

        if ($status === 204) {
            header_remove('Content-Type');
            header_remove('Cache-Control');
            return;
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}