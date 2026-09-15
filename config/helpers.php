<?php

declare(strict_types=1);

/**
 * Zorvex | Network — Global helper functions.
 *
 * Lightweight framework-free helpers used across the codebase.
 *
 * @package Zorvex\Config
 */

use Psr\Log\LoggerInterface;
use Zorvex\Core\Container;
use Zorvex\Core\Logger;

if (!function_exists('app')) {
    /**
     * Resolve a service from the application container (or the container itself).
     */
    function app(?string $abstract = null): mixed
    {
        $container = Container::instance();

        return $abstract === null ? $container : $container->get($abstract);
    }
}

if (!function_exists('config')) {
    /**
     * Read a configuration value using dot-notation, e.g. `database.host`.
     *
     * @return mixed
     */
    function config(?string $key = null, mixed $default = null): mixed
    {
        static $config = null;

        if ($config === null) {
            $config = require dirname(__DIR__) . '/config/app.php';
            $config = is_callable($config) ? $config() : $config;
        }

        if ($key === null) {
            return $config;
        }

        $value = $config;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }
}

if (!function_exists('env')) {
    /**
     * Read an environment variable with a fallback default.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = getenv($key);

        if ($value === false) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)'  => true,
            'false', '(false)' => false,
            'null', '(null)'  => null,
            'empty', '(empty)' => '',
            default           => $value,
        };
    }
}

if (!function_exists('logger')) {
    /**
     * Get the application PSR-3 logger.
     */
    function logger(): LoggerInterface
    {
        return app(LoggerInterface::class);
    }
}

if (!function_exists('now')) {
    /**
     * Current time formatted for MySQL (UTC).
     */
    function now(?string $time = null): string
    {
        return gmdate('Y-m-d H:i:s', $time ?? time());
    }
}

if (!function_exists('uid')) {
    /**
     * Generate a human friendly random order / reference identifier.
     */
    function uid(string $prefix = ''): string
    {
        $bytes = bin2hex(random_bytes(8));

        return $prefix === '' ? $bytes : $prefix . '-' . $bytes;
    }
}

if (!function_exists('normalize_phone')) {
    /**
     * Normalize an Iranian phone number to international format.
     */
    function normalize_phone(string $phone): string
    {
        $phone = preg_replace('/[^\d+]/', '', $phone) ?? '';

        if (str_starts_with($phone, '+98')) {
            return $phone;
        }

        if (str_starts_with($phone, '98')) {
            return '+' . $phone;
        }

        if (str_starts_with($phone, '09')) {
            return '+98' . substr($phone, 1);
        }

        if (str_starts_with($phone, '9')) {
            return '+98' . $phone;
        }

        return $phone;
    }
}

if (!function_exists('format_price')) {
    /**
     * Format a numeric price in Persian digits with thousands separators.
     */
    function format_price(float $amount, string $suffix = 'تومان'): string
    {
        $number = number_format($amount, 0, '.', ',');

        return fa_digits($number . ($suffix !== '' ? ' ' . $suffix : ''));
    }
}

if (!function_exists('fa_digits')) {
    /**
     * Convert English digits to Persian digits.
     */
    function fa_digits(string $value): string
    {
        $map = ['0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
                '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹'];

        return strtr($value, $map);
    }
}

if (!function_exists('en_digits')) {
    /**
     * Convert Persian/Arabic digits back to English digits.
     */
    function en_digits(string $value): string
    {
        $map = ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
                '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
                '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
                '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9'];

        return strtr($value, $map);
    }
}

if (!function_exists('format_bytes')) {
    /**
     * Format raw bytes into a human readable size string.
     */
    function format_bytes(int|float $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $bytes = max((float) $bytes, 0);
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return sprintf('%.' . $precision . 'f %s', $bytes / (1024 ** $power), $units[$power]);
    }
}

if (!function_exists('generate_vpn_username')) {
    /**
     * Generate a collision-resistant VPN client username from a Telegram ID.
     */
    function generate_vpn_username(int $telegramId): string
    {
        return 'z' . strtolower((string) $telegramId) . substr(md5((string) $telegramId), 0, 4);
    }
}

if (!function_exists('http_get_json')) {
    /**
     * Low-level HTTP GET returning decoded JSON, used by panel clients.
     */
    function http_get_json(string $url, array $headers = [], int $timeout = 15): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $headers,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false || $status >= 400) {
            throw new RuntimeException(
                sprintf('HTTP GET %s failed (%d): %s', $url, $status, $error ?: (string) $body),
                $status
            );
        }

        $decoded = json_decode((string) $body, true);

        if (!is_array($decoded)) {
            throw new RuntimeException(sprintf('Invalid JSON from %s', $url));
        }

        return $decoded;
    }
}

if (!function_exists('is_valid_card')) {
    /**
     * Validate an Iranian bank card number with the Luhn algorithm.
     */
    function is_valid_card(string $card): bool
    {
        $card = preg_replace('/[^\d]/', '', $card) ?? '';

        if (strlen($card) !== 16) {
            return false;
        }

        if (!in_array(substr($card, 0, 2), ['60', '58', '50', '62', '63', '64', '99', '61'], true)) {
            return false;
        }

        $sum = 0;

        for ($i = 0; $i < 16; $i++) {
            $digit = (int) $card[$i];

            if ($i % 2 === 0) {
                $digit *= 2;
                if ($digit > 9) {
                    $digit -= 9;
                }
            }

            $sum += $digit;
        }

        return $sum % 10 === 0;
    }
}

if (!function_exists('escape_html')) {
    /**
     * Escape a value for safe HTML output (XSS protection).
     */
    function escape_html(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('starts_with')) {
    /**
     * Check if a string starts with a given substring.
     */
    function starts_with(string $haystack, string $needle): bool
    {
        return str_starts_with($haystack, $needle);
    }
}

if (!function_exists('hex_encode')) {
    /**
     * Encode a binary payload as a URL-safe base64 string (Telegram web_app data).
     */
    function hex_encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

if (!function_exists('hex_decode')) {
    /**
     * Decode a URL-safe base64 string produced by hex_encode().
     */
    function hex_decode(string $value): string|false
    {
        $value = strtr($value, '-_', '+/');
        $pad   = strlen($value) % 4;

        if ($pad !== 0) {
            $value .= str_repeat('=', 4 - $pad);
        }

        return base64_decode($value, true);
    }
}

if (!function_exists('json_ok')) {
    /**
     * Build a JSON-ready success envelope.
     */
    function json_ok(array $data = []): array
    {
        return ['ok' => true, 'data' => $data];
    }
}

if (!function_exists('json_fail')) {
    /**
     * Build a JSON-ready error envelope.
     */
    function json_fail(string $message, int $code = 400, array $details = []): array
    {
        return ['ok' => false, 'error' => $message, 'code' => $code, 'details' => $details];
    }
}