<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/src/bootstrap.php';

use InstanFoto\Cleanup;
use InstanFoto\Config;
use InstanFoto\Database;

$config = Config::fromEnvironment();
Database::init($config);

try {
    $result = Cleanup::run($config);
    echo 'Sesi kedaluwarsa dihapus: ' . $result['deleted'] . PHP_EOL;
    echo 'Masa simpan: ' . $result['retentionDays'] . ' hari' . PHP_EOL;
} finally {
    Database::close();
}