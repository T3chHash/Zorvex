<?php

declare(strict_types=1);

/**
 * Zorvex | Network — Telegram mini-app (web app) HTML shell.
 *
 * @package Zorvex\Public\App
 */

use Zorvex\Core\Response;

$appUrl = (string) config('app.url', '');
$appName = (string) config('app.name', 'Zorvex | Network');

$html = <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="color-scheme" content="dark">
    <meta property="og:title" content="{$appName}">
    <title>{$appName}</title>
    <link rel="stylesheet" href="{$appUrl}/app/assets/css/app.css">
</head>
<body>
    <div id="app">
        <!-- Top header -->
        <header class="app-header glass">
            <div class="brand">
                <span class="brand-badge">Zorvex</span>
                <span class="brand-sub">| Network</span>
            </div>
            <div class="balance-chip" id="balance-chip" style="display:none;">
                <span class="balance-label">موجودی</span>
                <span id="balance-value" class="balance-value">۰</span>
            </div>
        </header>

        <!-- Router outlet -->
        <main id="view" class="view" aria-live="polite">
            <div class="loading-spinner"></div>
        </main>

        <!-- Bottom navigation -->
        <nav class="tabbar glass" id="tabbar">
            <button class="tab active" data-route="/">خرید</button>
            <button class="tab" data-route="/services">سرویس‌ها</button>
            <button class="tab" data-route="/account">حساب</button>
            <button class="tab" data-route="/support">پشتیبانی</button>
        </nav>
    </div>

    <script src="https://telegram.org/js/telegram-web-app.js"></script>
    <script>
    window.ZORVEX_API_BASE = "{$appUrl}";
    window.ZORVEX_APP_NAME = "{$appName}";
    </script>
    <script src="{$appUrl}/app/assets/js/state.js"></script>
    <script src="{$appUrl}/app/assets/js/api.js"></script>
    <script src="{$appUrl}/app/assets/js/router.js"></script>
    <script src="{$appUrl}/app/assets/js/pages.js"></script>
    <script src="{$appUrl}/app/assets/js/app.js"></script>
</body>
</html>
HTML;

Response::html($html)->send();