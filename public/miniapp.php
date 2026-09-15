<?php

declare(strict_types=1);

/**
 * Zorvex | Network — mini-app shell entry point.
 *
 * Serves the Telegram-compatible SPA (the app/index.php shell + assets).
 *
 * @package Zorvex\Public
 */

// Load the container just for configuration/URL helpers.
require dirname(__DIR__) . '/bootstrap/app.php';

// The actual SPA lives at public/app/index.php.
require __DIR__ . '/app/index.php';