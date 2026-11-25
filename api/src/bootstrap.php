<?php

declare(strict_types=1);

require_once __DIR__ . '/Env.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'InstanFoto\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file = __DIR__ . '/' . str_replace('\\', '/', $relative) . '.php';

    if (is_file($file)) {
        require_once $file;
    }
});

InstanFoto\Env::load(dirname(__DIR__, 2) . '/.env');