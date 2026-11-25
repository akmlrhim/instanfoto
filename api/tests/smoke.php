<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$port = (int) (getenv('TEST_API_PORT') ?: 8123);
$base = 'http://127.0.0.1:' . $port;
$apiKey = bin2hex(random_bytes(32));
$cronSecret = 'cron-secret-for-tests';
$dataDir = sys_get_temp_dir() . '/instanfoto-php-' . bin2hex(random_bytes(4));
$logFile = $dataDir . '.log';
$publicDir = $root . '/api/public';
$router = $publicDir . '/router.php';

$failures = 0;

function check(string $label, bool $ok, string $detail = ''): void
{
    global $failures;

    if (!$ok) {
        $failures += 1;
    }

    echo '[' . ($ok ? 'PASS' : 'FAIL') . '] ' . $label . ($detail === '' ? '' : ' — ' . $detail) . PHP_EOL;
}

function request(string $method, string $path, array $headers = [], string|array|null $body = null): array
{
    global $base;

    $channel = curl_init($base . $path);
    $lines = ['Expect:'];

    foreach ($headers as $name => $value) {
        $lines[] = $name . ': ' . $value;
    }

    curl_setopt_array($channel, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $lines,
        CURLOPT_TIMEOUT => 60,
    ]);

    if ($body !== null) {
        curl_setopt($channel, CURLOPT_POSTFIELDS, $body);
    }

    $raw = curl_exec($channel);

    if ($raw === false) {
        $message = curl_error($channel);
        curl_close($channel);
        throw new RuntimeException('Permintaan gagal: ' . $message);
    }

    $status = (int) curl_getinfo($channel, CURLINFO_RESPONSE_CODE);
    $headerSize = (int) curl_getinfo($channel, CURLINFO_HEADER_SIZE);
    curl_close($channel);

    $headerBlock = substr($raw, 0, $headerSize);
    $responseBody = substr($raw, $headerSize);
    $parsed = [];

    foreach (preg_split('/\r?\n/', $headerBlock) ?: [] as $line) {
        $separator = strpos($line, ':');

        if ($separator === false) {
            continue;
        }

        $parsed[strtolower(trim(substr($line, 0, $separator)))] = trim(substr($line, $separator + 1));
    }

    return ['status' => $status, 'body' => $responseBody, 'headers' => $parsed];
}

function apiKeyHeaders(string $apiKey): array
{
    return ['X-Api-Key' => $apiKey];
}

function createSession(string $apiKey, ?string $event = null): array
{
    $headers = apiKeyHeaders($apiKey);
    $body = null;

    if ($event !== null) {
        $headers['Content-Type'] = 'application/json';
        $body = json_encode(['event' => $event], JSON_THROW_ON_ERROR);
    }

    $response = request('POST', '/api/sessions', $headers, $body);

    return ['status' => $response['status'], 'data' => json_decode($response['body'], true)];
}

function upload(string $apiKey, string $sessionId, string $file, string $type = 'image/jpeg'): array
{
    $response = request(
        'POST',
        '/api/sessions/' . $sessionId . '/photos',
        apiKeyHeaders($apiKey),
        ['photo' => new CURLFile($file, $type, 'photo.jpg')],
    );

    return ['status' => $response['status'], 'data' => json_decode($response['body'], true)];
}

function makeJpeg(string $path, int $width = 40, int $height = 30): void
{
    $image = imagecreatetruecolor($width, $height);
    imagefill($image, 0, 0, imagecolorallocate($image, 200, 100, 50));
    imagejpeg($image, $path, 85);
    imagedestroy($image);
}

function temporaryFile(string $contents): string
{
    $file = tempnam(sys_get_temp_dir(), 'instanfoto-test');
    file_put_contents($file, $contents);

    return $file;
}

function withHugeDimensions(string $jpeg): string
{
    $markers = ["\xFF\xC0", "\xFF\xC1", "\xFF\xC2"];

    foreach ($markers as $marker) {
        $at = strpos($jpeg, $marker);

        if ($at !== false && $at + 9 < strlen($jpeg)) {
            $jpeg = substr_replace($jpeg, pack('n', 20000), $at + 5, 2);
            $jpeg = substr_replace($jpeg, pack('n', 20000), $at + 7, 2);

            return $jpeg;
        }
    }

    throw new RuntimeException('SOF marker not found');
}

function removeTree(string $path): void
{
    if (is_file($path)) {
        @unlink($path);
        return;
    }

    if (!is_dir($path)) {
        return;
    }

    foreach (new FilesystemIterator($path, FilesystemIterator::SKIP_DOTS) as $item) {
        removeTree($item->getPathname());
    }

    @rmdir($path);
}

if (!is_dir($dataDir)) {
    mkdir($dataDir, 0775, true);
}

$environment = getenv();
$environment['API_KEY'] = $apiKey;
$environment['BASE_URL'] = 'http://127.0.0.1:5173';
$environment['DATA_DIR'] = $dataDir;
$environment['CRON_SECRET'] = $cronSecret;
$environment['MAX_PHOTOS_PER_SESSION'] = '3';
$environment['RETENTION_DAYS'] = '30';

$command = [
    PHP_BINARY,
    '-d',
    'upload_max_filesize=8M',
    '-d',
    'post_max_size=10M',
    '-d',
    'error_log=' . $logFile,
    '-S',
    '127.0.0.1:' . $port,
    '-t',
    $publicDir,
    $router,
];

$descriptors = [
    0 => ['pipe', 'r'],
    1 => ['file', $logFile, 'a'],
    2 => ['file', $logFile, 'a'],
];

$process = proc_open($command, $descriptors, $pipes, $root, $environment);
$ready = false;

if (is_resource($process)) {
    if (isset($pipes[0]) && is_resource($pipes[0])) {
        fclose($pipes[0]);
    }

    for ($attempt = 0; $attempt < 100; $attempt += 1) {
        try {
            if (request('GET', '/api/health')['status'] === 200) {
                $ready = true;
                break;
            }
        } catch (Throwable) {
        }

        usleep(100000);
    }
}

$jpegFile = temporaryFile('');
makeJpeg($jpegFile);
$jpeg = file_get_contents($jpegFile) ?: '';
$polyglotFile = temporaryFile($jpeg . '<?php echo "pwned"; ?>');
$phpFile = temporaryFile('<?php echo "pwned"; ?>');
$hugeFile = temporaryFile(withHugeDimensions($jpeg));
$bigFile = temporaryFile(str_repeat("\0", 11 * 1024 * 1024));

try {
    check('Server siap', $ready, $base);

    if (!$ready) {
        echo file_get_contents($logFile) ?: '';
        throw new RuntimeException('Server API tidak siap');
    }

    $health = request('GET', '/api/health');
    $required = [
        'x-content-type-options',
        'x-frame-options',
        'referrer-policy',
        'x-robots-tag',
        'permissions-policy',
        'content-security-policy',
    ];
    $present = array_filter($required, static fn (string $name): bool => isset($health['headers'][$name]));
    check('SEC-42', count($present) === count($required), 'semua header wajib hadir');
    check('SEC-43', !isset($health['headers']['access-control-allow-origin']), 'tidak ada header CORS');
    check('T-16', !isset($health['headers']['x-powered-by']), 'tidak ada X-Powered-By');
    check('SEC-82', $health['body'] === '{"ok":true}', 'health hanya ok true');

    $noKey = request('POST', '/api/sessions');
    check('T-01', $noKey['status'] === 401, 'tanpa key -> ' . $noKey['status']);

    $wrongKey = request('POST', '/api/sessions', ['X-Api-Key' => 'wrong-key']);
    check('T-02', $wrongKey['status'] === 401, 'key salah -> ' . $wrongKey['status']);

    $created = createSession($apiKey, 'Uji PHP');
    check(
        'Alur',
        $created['status'] === 201 && preg_match('/^[a-z0-9]{12}$/', (string) ($created['data']['id'] ?? '')) === 1,
        'buat sesi -> ' . $created['status'],
    );
    $sessionId = (string) ($created['data']['id'] ?? '');

    $badEvent = createSession($apiKey, str_repeat('x', 5000));
    check('T-09', in_array($badEvent['status'], [413, 422], true), 'event 5000 -> ' . $badEvent['status']);

    $script = createSession($apiKey, '<script>alert(1)</script>');
    $scriptAlbum = request('GET', '/api/albums/' . (string) ($script['data']['id'] ?? ''));
    check('T-19', !str_contains($scriptAlbum['body'], '<script>'), 'nama acara tidak muncul mentah di album');

    $traversal = request('GET', '/api/albums/%2E%2E%2F%2E%2E%2Fetc');
    check('T-08', $traversal['status'] === 404, 'path traversal -> ' . $traversal['status']);

    $phpUpload = upload($apiKey, $sessionId, $phpFile);
    check('T-03', $phpUpload['status'] === 415, 'file PHP -> ' . $phpUpload['status']);

    $goodUpload = upload($apiKey, $sessionId, $jpegFile);
    check('T-04', $goodUpload['status'] === 201, 'JPEG valid -> ' . $goodUpload['status']);

    $polyglotUpload = upload($apiKey, $sessionId, $polyglotFile);
    check('T-05', $polyglotUpload['status'] === 201, 'polyglot -> ' . $polyglotUpload['status']);

    $bigUpload = upload($apiKey, $sessionId, $bigFile);
    check('T-06', $bigUpload['status'] === 413, 'unggah 11 MB -> ' . $bigUpload['status']);

    $hugeUpload = upload($apiKey, $sessionId, $hugeFile);
    check('T-07', $hugeUpload['status'] === 422, 'dimensi 20000px -> ' . $hugeUpload['status']);

    $album = request('GET', '/api/albums/' . $sessionId);
    $albumData = json_decode($album['body'], true);
    $photos = is_array($albumData['photos'] ?? null) ? $albumData['photos'] : [];
    check('FR-04', $album['status'] === 200 && count($photos) === 2, 'album berisi 2 foto -> ' . $album['status']);

    if (count($photos) === 2) {
        $web = request('GET', $photos[0]['web']);
        check(
            'T-04b',
            $web['status'] === 200 && !str_contains($web['body'], 'Exif'),
            'EXIF hilang dari foto web',
        );
        check(
            'T-04c',
            getimagesizefromstring($web['body']) !== false,
            'foto web tetap JPEG yang bisa dibaca',
        );

        $polyglot = request('GET', $photos[1]['web']);
        check('T-05b', !str_contains($polyglot['body'], '<?php'), 'payload PHP hilang dari foto web');

        $thumb = request('GET', $photos[0]['thumb']);
        check('Thumb', $thumb['status'] === 200, 'thumbnail tersedia -> ' . $thumb['status']);
    }

    $finished = request('POST', '/api/sessions/' . $sessionId . '/finish', apiKeyHeaders($apiKey));
    $finishData = json_decode($finished['body'], true);
    check(
        'FR-03',
        $finished['status'] === 200 && str_ends_with((string) ($finishData['url'] ?? ''), '/a/' . $sessionId),
        'selesai -> ' . $finished['status'],
    );

    $afterFinish = upload($apiKey, $sessionId, $jpegFile);
    check('T-10', $afterFinish['status'] === 409, 'unggah setelah selesai -> ' . $afterFinish['status']);

    $bulk = createSession($apiKey, 'Uji batas foto');
    $bulkId = (string) ($bulk['data']['id'] ?? '');
    $lastStatus = 201;

    for ($index = 0; $index < 3; $index += 1) {
        $result = upload($apiKey, $bulkId, $jpegFile);
        $lastStatus = $result['status'];

        if ($lastStatus !== 201) {
            break;
        }
    }

    $overLimit = upload($apiKey, $bulkId, $jpegFile);
    check('T-11', $lastStatus === 201 && $overLimit['status'] === 422, 'foto ke-4 dari batas 3 -> ' . $overLimit['status']);

    clearstatcache();
    check('Penyimpanan', is_dir($dataDir . '/uploads/' . $bulkId), 'folder foto sesi ada di disk lokal');

    $removed = request('DELETE', '/api/sessions/' . $bulkId, apiKeyHeaders($apiKey));
    clearstatcache();
    check('SEC-61', $removed['status'] === 204 && $removed['body'] === '', 'hapus mengembalikan 204 tanpa body');
    check('SEC-61 file', !is_dir($dataDir . '/uploads/' . $bulkId), 'folder foto ikut terhapus');

    $removedAgain = request('DELETE', '/api/sessions/' . $bulkId, apiKeyHeaders($apiKey));
    check('T-23', $removedAgain['status'] === 404, 'hapus ulang mengembalikan 404');

    check('SEC-80', str_contains((string) file_get_contents($logFile), 'uploaded'), 'log aplikasi mencatat upload');
    check('SEC-80 key', !str_contains((string) file_get_contents($logFile), $apiKey), 'log tidak memuat API key');

    $old = createSession($apiKey);
    $oldId = (string) ($old['data']['id'] ?? '');
    upload($apiKey, $oldId, $jpegFile);

    $connection = new PDO('sqlite:' . $dataDir . '/app.sqlite');
    $statement = $connection->prepare("UPDATE sessions SET created_at = '2000-01-01 00:00:00' WHERE id = ?");
    $statement->execute([$oldId]);
    $connection = null;

    $cleanupCommand = [PHP_BINARY, $root . '/api/bin/cleanup.php'];
    $cleanupProcess = proc_open(
        $cleanupCommand,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $cleanupPipes,
        $root,
        $environment,
    );
    $cleanupOutput = is_resource($cleanupProcess) ? stream_get_contents($cleanupPipes[1]) : '';
    $cleanupErrors = is_resource($cleanupProcess) ? stream_get_contents($cleanupPipes[2]) : '';

    if (is_resource($cleanupProcess)) {
        proc_close($cleanupProcess);
    }

    check(
        'SEC-60 output',
        str_contains((string) $cleanupOutput, 'Sesi kedaluwarsa dihapus: 1'),
        trim((string) $cleanupOutput) . ' ' . trim((string) $cleanupErrors),
    );

    $afterCleanup = request('GET', '/api/albums/' . $oldId);
    clearstatcache();
    check(
        'SEC-60',
        $afterCleanup['status'] === 404 && !is_dir($dataDir . '/uploads/' . $oldId),
        'cleanup menghapus sesi dan file kedaluwarsa',
    );

    $noCron = request('GET', '/api/cron/cleanup');
    check('Cron auth', $noCron['status'] === 401, 'tanpa secret -> ' . $noCron['status']);

    $withCron = request('GET', '/api/cron/cleanup', ['Authorization' => 'Bearer ' . $cronSecret]);
    check('Cron endpoint', $withCron['status'] === 200, 'dengan secret -> ' . $withCron['status']);

    $checkEnvProcess = proc_open(
        [PHP_BINARY, $root . '/api/bin/check-env.php'],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $checkEnvPipes,
        $root,
        $environment,
    );
    $checkEnvOutput = is_resource($checkEnvProcess) ? stream_get_contents($checkEnvPipes[1]) : '';

    if (is_resource($checkEnvProcess)) {
        proc_close($checkEnvProcess);
    }

    check('SEC-60 CLI', str_contains((string) $checkEnvOutput, '[PASS] PHP'), 'skrip lingkungan tetap berjalan');

    $albumThrottled = 0;

    for ($index = 1; $index <= 65; $index += 1) {
        if (request('GET', '/api/albums/xxxxxxxxxxxx')['status'] === 429) {
            $albumThrottled = $index;
            break;
        }
    }

    check(
        'SEC-70',
        $albumThrottled > 0 && $albumThrottled <= 31,
        '429 mulai pada permintaan ke-' . $albumThrottled,
    );

    $connection = new PDO('sqlite:' . $dataDir . '/app.sqlite');
    $connection->exec("DELETE FROM rate_limits WHERE bucket = 'authfail'");
    $connection = null;

    $authStatus = 0;

    for ($index = 0; $index < 10; $index += 1) {
        $response = request(
            'POST',
            '/api/sessions',
            ['X-Api-Key' => 'salah', 'Content-Type' => 'application/json'],
            '{}',
        );
        $authStatus = $response['status'];
    }

    $banned = createSession($apiKey);
    check('SEC-71', $authStatus === 401 && $banned['status'] === 429, 'setelah 10 gagal, tulis ditolak ' . $banned['status']);
} catch (Throwable $error) {
    check('Test berjalan', false, $error->getMessage());
} finally {
    if (is_resource($process)) {
        proc_terminate($process);

        for ($attempt = 0; $attempt < 50; $attempt += 1) {
            $status = proc_get_status($process);

            if (!$status['running']) {
                break;
            }

            usleep(100000);
        }

        proc_close($process);
    }

    foreach ([$jpegFile, $polyglotFile, $phpFile, $hugeFile, $bigFile] as $file) {
        @unlink($file);
    }

    removeTree($dataDir);
    @unlink($logFile);
}

echo PHP_EOL . ($failures === 0 ? 'Smoke test: PASS' : 'Smoke test: FAIL (' . $failures . ')') . PHP_EOL;
exit($failures === 0 ? 0 : 1);