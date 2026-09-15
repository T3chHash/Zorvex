<?php

declare(strict_types=1);

/**
 * Zorvex | Network — admin panel single-page app.
 *
 * @package Zorvex\Public
 */

use Zorvex\Core\Response;

// Load the container for configuration.
require dirname(__DIR__) . '/bootstrap/app.php';

$appUrl = (string) config('app.url', '');
$adminTitle = config('app.name', 'Zorvex | Network') . ' — پنل مدیریت';

$html = <<<HTML
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$adminTitle}</title>
    <meta name="color-scheme" content="dark">
    <link rel="stylesheet" href="{$appUrl}/app/assets/css/app.css">
    <style>
        #admin-root { max-width: 1200px; margin: 0 auto; padding: 24px 16px 80px; }
        .admin-header { display:flex; align-items:center; justify-content:space-between; gap:16px; flex-wrap:wrap; margin-bottom:28px; }
        .brand { display:flex; align-items:center; gap:10px; font-weight:800; font-size:1.3rem; }
        .brand-badge { background:linear-gradient(135deg,var(--brand),#00e0c6); -webkit-background-clip:text; background-clip:text; -webkit-text-fill-color:transparent; }
        .stat-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; margin-bottom:28px; }
        .stat-card { background:var(--glass); border:1px solid var(--border); border-radius:16px; padding:18px; backdrop-filter:blur(12px); }
        .stat-card .label { color:var(--muted); font-size:0.82rem; margin-bottom:6px; }
        .stat-card .value { font-size:1.55rem; font-weight:800; }
        .admin-tabs { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:24px; }
        .admin-tabs button { background:var(--glass); border:1px solid var(--border); color:var(--text); padding:10px 18px; border-radius:12px; cursor:pointer; font-weight:600; transition:all .2s; }
        .admin-tabs button.active, .admin-tabs button:hover { background:var(--brand); border-color:var(--brand); color:#fff; }
        .panel { background:var(--glass); border:1px solid var(--border); border-radius:16px; padding:20px; backdrop-filter:blur(12px); }
        table { width:100%; border-collapse:collapse; font-size:.9rem; }
        th,td { text-align:right; padding:10px 8px; border-bottom:1px solid var(--border); }
        th { color:var(--muted); font-weight:600; }
        .badge { display:inline-block; padding:3px 10px; border-radius:999px; font-size:.75rem; font-weight:700; }
        .badge.ok { background:rgba(34,197,94,.15); color:#4ade80; }
        .badge.err { background:rgba(239,68,68,.15); color:#f87171; }
        .badge.warn { background:rgba(250,204,21,.15); color:#facc15; }
        .btn { background:var(--brand); color:#fff; border:0; padding:9px 16px; border-radius:10px; cursor:pointer; font-weight:600; transition:opacity .2s; }
        .btn:hover { opacity:.85; }
        .btn.ghost { background:transparent; border:1px solid var(--border); }
        .toast-ok { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:#16a34a; color:#fff; padding:10px 20px; border-radius:12px; z-index:99; box-shadow:0 8px 24px rgba(0,0,0,.4); }
        .toast-err { position:fixed; bottom:24px; left:50%; transform:translateX(-50%); background:#dc2626; color:#fff; padding:10px 20px; border-radius:12px; z-index:99; box-shadow:0 8px 24px rgba(0,0,0,.4); }
        .empty { color:var(--muted); text-align:center; padding:30px 0; }
    </style>
</head>
<body>
    <div id="admin-root">
        <nav class="admin-header">
            <div class="brand"><span class="brand-badge">Zorvex | Network</span></div>
            <input id="admin-token" placeholder="توکن مدیریت" style="background:var(--glass);border:1px solid var(--border);color:var(--text);padding:8px 12px;border-radius:10px;width:200px;">
        </nav>
        <div class="stat-grid" id="stat-grid"></div>
        <div class="admin-tabs">
            <button data-tab="stats" class="active">آمار</button>
            <button data-tab="servers">سرورها</button>
            <button data-tab="users">کاربران</button>
            <button data-tab="payments">پرداخت‌ها</button>
        </div>
        <div class="panel" id="admin-content"></div>
    </div>

    <template id="server-form">
        <form onsubmit="return false;">
            <input name="name" placeholder="نام سرور" required>
            <input name="url" type="url" placeholder="https://panel.example.com" required>
            <select name="type">
                <option value="marzban">Marzban</option>
                <option value="3x-ui">3x-ui</option>
            </select>
            <input name="api_key" type="password" placeholder="API Key / password" required>
            <button class="btn" type="submit">ذخیره</button>
        </form>
    </template>
    <script>
    window.ZORVEX_API_BASE = "{$appUrl}";
    </script>
    <script src="{$appUrl}/app/assets/js/api.js"></script>
    <script src="{$appUrl}/app/assets/js/admin.js"></script>
</body>
</html>
HTML;

Response::html($html)->send();