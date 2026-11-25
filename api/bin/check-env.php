<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use InstanFoto\Config;
use InstanFoto\Database;

$config = Config::fromEnvironment();
$results = [];

$record = static function (string $label, bool $ok, string $detail) use (&$results): void {
    $results[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
};

$record('PHP', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION . ' (minimal 8.2)');
$record(
    'API_KEY',
    strlen($config->apiKey) >= 32,
    strlen($config->apiKey) >= 32 ? 'terisi' : 'kosong atau kurang dari 32 karakter',
);
$record('BASE_URL', str_starts_with($config->baseUrl, 'http'), $config->baseUrl);
$record(
    'Ekstensi GD',
    extension_loaded('gd') && (imagetypes() & IMG_JPG) !== 0,
    extension_loaded('gd') ? 'JPEG tersedia' : 'gd tidak aktif',
);
$record(
    'Ekstensi EXIF',
    extension_loaded('exif'),
    extension_loaded('exif') ? 'orientasi foto terbaca' : 'exif tidak aktif',
);
$record(
    'PDO SQLite',
    extension_loaded('pdo_sqlite'),
    extension_loaded('pdo_sqlite') ? 'driver tersedia' : 'pdo_sqlite tidak aktif',
);

try {
    Database::init($config);
    Database::query('SELECT 1 AS ok');
    $record('Koneksi database', true, 'skema siap di ' . $config->databasePath);
} catch (Throwable $error) {
    $record('Koneksi database', false, $error->getMessage());
}

try {
    if (!is_dir($config->dataDir)) {
        mkdir($config->dataDir, 0775, true);
    }

    $probe = $config->dataDir . '/.write-test';
    file_put_contents($probe, 'ok');
    @unlink($probe);
    $record('Direktori data', true, $config->dataDir . ' bisa ditulis');
} catch (Throwable $error) {
    $record('Direktori data', false, $error->getMessage());
}

try {
    if (!is_dir($config->uploadDir)) {
        mkdir($config->uploadDir, 0775, true);
    }

    $record('Direktori unggahan', is_dir($config->uploadDir), $config->uploadDir);
} catch (Throwable $error) {
    $record('Direktori unggahan', false, $error->getMessage());
}

$record(
    'CRON_SECRET',
    $config->cronSecret !== '',
    $config->cronSecret !== '' ? 'terisi' : 'kosong (endpoint cleanup akan menolak semua)',
);

echo 'Pemeriksaan lingkungan InstanFoto' . PHP_EOL;
echo str_repeat('=', 64) . PHP_EOL;

foreach ($results as $result) {
    echo '[' . ($result['ok'] ? 'PASS' : 'FAIL') . '] ' . $result['label'] . ': ' . $result['detail'] . PHP_EOL;
}

echo PHP_EOL . 'Periksa manual di server PHP:' . PHP_EOL;

$manual = [
    'DocumentRoot diarahkan ke api/public dan mod_rewrite Apache aktif',
    'HTTPS aktif dan sertifikat masih berlaku',
    'Environment API_KEY, BASE_URL, dan CRON_SECRET terisi untuk produksi',
    'Cron harian memanggil GET /api/cron/cleanup dengan Authorization: Bearer CRON_SECRET',
    'api/data/ dan api/data/uploads/ dapat ditulis proses PHP, tetapi tidak dapat diakses langsung dari web',
    'Batas upload_max_filesize minimal 6M dan post_max_size minimal 8M',
    'Cadangan berkala untuk app.sqlite dan folder uploads',
    'Kuota disk dan bandwidth cukup untuk acara',
];

foreach ($manual as $item) {
    echo '- ' . $item . PHP_EOL;
}

Database::close();

exit(in_array(false, array_column($results, 'ok'), true) ? 1 : 0);