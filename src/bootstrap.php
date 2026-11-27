<?php
/**
 * Shared setup for the public entry points: a tiny autoloader (no Composer needed)
 * and the location of the generated country data.
 */

declare(strict_types=1);

const COUNTRY_DATA_FILE = __DIR__ . '/../data/countries.json';

spl_autoload_register(static function (string $className): void {
    $prefix = 'CountryQuiz\\';
    if (str_starts_with($className, $prefix)) {
        $filePath = __DIR__ . '/' . substr($className, strlen($prefix)) . '.php';
        if (is_file($filePath)) {
            require $filePath;
        }
    }
});
