<?php
declare(strict_types=1);

// Bootstrap PHPUnit: autoload Composer + namespace Tests\Support\ (non in composer.json: evita di toccare composer.lock).
require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $prefix = 'Tests\\Support\\';
    if (str_starts_with($class, $prefix)) {
        $file = __DIR__ . '/Support/' . substr($class, strlen($prefix)) . '.php';
        if (is_file($file)) {
            require $file;
        }
    }
});
