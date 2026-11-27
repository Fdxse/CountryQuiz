<?php
/**
 * Shared setup for the public entry points: a tiny autoloader (no Composer needed)
 * and the location of the generated country data.
 */

declare(strict_types=1);

// Only meant to be included by index.php / api.php, never opened directly in a browser.
if (count(get_included_files()) === 1) {
    http_response_code(404);
    exit;
}

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
