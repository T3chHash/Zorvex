<?php

declare(strict_types=1);

/**
 * Zorvex | Network — bootstrap script.
 *
 * Loads environment overrides, registers the Composer autoloader and returns
 * the booted application container.
 *
 * @package Zorvex\Bootstrap
 */

// 1. Load environment overrides from `.env` (simple key=value parser).
if (file_exists(dirname(__DIR__) . '/.env')) {
    $envFile = file(dirname(__DIR__) . '/.env', FILE_IGNORE_NEW_LINES);

    if ($envFile !== false) {
        foreach ($envFile as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, '"');

            if ($key !== '' && getenv($key) === false) {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
            }
        }
    }
}

// 2. Composer autoloader (required).
$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (!file_exists($autoload)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok' => false,
        'error' => 'Composer dependencies not installed. Run `composer install --no-dev --optimize-autoloader`.',
    ]);
    exit;
}

require $autoload;

// 3. Boot the container.
$container = Zorvex\Core\Bootstrap::create()->boot();

return $container;