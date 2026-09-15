<?php

declare(strict_types=1);

/**
 * Zorvex | Network — Application configuration.
 *
 * Central configuration store. Values are read from environment variables
 * (see /.env.example) with sensible production defaults baked in.
 *
 * @package Zorvex\Config
 */

/** Supported VPN panel types. */
const PANEL_TYPE_MARZBAN = 'marzban';
const PANEL_TYPE_XUI     = '3x-ui';

/** Payment method identifiers. */
const PAYMENT_CRYPTO      = 'crypto';
const PAYMENT_CARD_TO_CARD = 'card_to_card';
const PAYMENT_GATEWAY      = 'gateway';

/** Subscription / payment statuses. */
const STATUS_PENDING   = 'pending';
const STATUS_PROCESSING = 'processing';
const STATUS_COMPLETED = 'completed';
const STATUS_FAILED    = 'failed';
const STATUS_CANCELLED = 'cancelled';
const STATUS_ACTIVE    = 'active';
const STATUS_EXPIRED   = 'expired';
const STATUS_SUSPENDED = 'suspended';

/** Bot command rate limiting (seconds between duplicate commands). */
const COMMAND_RATE_LIMIT_SECONDS = 2;

return static function (): array {
    $env = static function (string $key, string $default = ''): string {
        $value = getenv($key);

        return $value === false ? $default : $value;
    };

    return [
        'app' => [
            'name'        => 'Zorvex | Network',
            'env'         => $env('APP_ENV', 'production'),
            'debug'       => $env('APP_DEBUG', 'false') === 'true',
            'url'         => $env('APP_URL', 'https://zorvex.example.com'),
            'timezone'    => $env('APP_TIMEZONE', 'Asia/Tehran'),
            'locale'      => 'fa',
            'key'         => $env('APP_KEY', ''),
            'docs'        => 'https://zorvex.example.com/docs',
        ],

        'database' => [
            'host'     => $env('DB_HOST', 'mysql'),
            'port'     => (int) $env('DB_PORT', '3306'),
            'name'     => $env('DB_DATABASE', 'zorvex'),
            'user'     => $env('DB_USERNAME', 'zorvex'),
            'password' => $env('DB_PASSWORD', 'zorvex_secret'),
            'charset'  => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'pool'     => [
                'min'           => (int) $env('DB_POOL_MIN', '1'),
                'max'           => (int) $env('DB_POOL_MAX', '10'),
                'idle_timeout'  => (int) $env('DB_IDLE_TIMEOUT', '60'),
            ],
            'options'  => [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
                \PDO::ATTR_STRINGIFY_FETCHES  => false,
            ],
        ],

        'telegram' => [
            'token'   => $env('TELEGRAM_BOT_TOKEN', ''),
            'webhook' => env('APP_URL', 'https://zorvex.example.com') . '/webhook',
            'admin_ids' => array_values(array_filter(array_map('intval', explode(',', $env('TELEGRAM_ADMIN_IDS', ''))))),
            'support_username' => $env('TELEGRAM_SUPPORT_USERNAME', 'zorvex_support'),
            'rate_limit' => COMMAND_RATE_LIMIT_SECONDS,
            // Mini-app deep-link domain allowed list (comma separated) for
            // anti-clickjacking / origin validation.
            'allowed_origins' => array_values(array_filter(array_map('trim',
                explode(',', $env('TELEGRAM_ALLOWED_ORIGINS', 'zorvex.example.com'))))),
        ],

        'panels' => [
            'default' => $env('PANEL_DEFAULT_TYPE', PANEL_TYPE_MARZBAN),
            'timeout' => (int) $env('PANEL_TIMEOUT', '15'),
            'retries' => (int) $env('PANEL_RETRIES', '3'),
        ],

        'payment' => [
            'currencies' => ['USDT', 'TRX', 'TON', 'BTC'],
            'crypto' => [
                'network'       => $env('CRYPTO_NETWORK', 'TRC20'),
                'wallet'        => $env('CRYPTO_WALLET', ''),
                'usdt_rate'     => (float) $env('CRYPTO_USDT_RATE', '1'),
                'min_confirmations' => (int) $env('CRYPTO_MIN_CONFIRMATIONS', '1'),
            ],
            'card_to_card' => [
                'enabled'  => $env('CARD_TO_CARD_ENABLED', 'true') === 'true',
                'card_number' => $env('CARD_TO_CARD_NUMBER', '6037-****-****-****'),
                'card_holder' => $env('CARD_TO_CARD_HOLDER', ''),
                'auto_verify' => $env('CARD_TO_CARD_AUTO_VERIFY', 'false') === 'true',
            ],
            'zarinpal' => [
                'enabled'   => $env('ZARINPAL_ENABLED', 'true') === 'true',
                'merchant_id' => $env('ZARINPAL_MERCHANT_ID', ''),
                'sandbox'   => $env('ZARINPAL_SANDBOX', 'true') === 'true',
                'callback'  => env('APP_URL', 'https://zorvex.example.com') . '/payment/callback/zarinpal',
                'currency'  => 'IRR', // Toman amounts are converted to IRR.
            ],
        ],

        'admin' => [
            'session_ttl' => (int) $env('ADMIN_SESSION_TTL', '28800'),
        ],

        'mini_app' => [
            'token_ttl' => (int) $env('MINIAPP_TOKEN_TTL', '86400'),
        ],

        'log' => [
            'path'  => $env('LOG_PATH', __DIR__ . '/../storage/logs/app.log'),
            'level' => $env('LOG_LEVEL', 'debug'),
        ],
    ];
};