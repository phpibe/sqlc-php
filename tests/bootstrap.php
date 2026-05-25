<?php

declare(strict_types=1);

// Load doctrine/inflector if available (installed via apt or composer)
foreach ([
    __DIR__ . '/../vendor/autoload.php',
    '/usr/share/php/Doctrine/Inflector/autoload.php',
] as $autoload) {
    if (file_exists($autoload)) {
        require_once $autoload;
        break;
    }
}

spl_autoload_register(function (string $class): void {
    $maps = [
        'SqlcPhp\\'     => __DIR__ . '/../src/',
        'SqlcPhp\\Tests\\' => __DIR__ . '/',
    ];

    foreach ($maps as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) continue;
        $file = $base . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (file_exists($file)) {
            require $file;
            return;
        }
    }
});
