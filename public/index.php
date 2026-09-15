<?php

declare(strict_types=1);

/**
 * Zorvex | Network — main HTTP entry point (webhook + REST API).
 *
 * @package Zorvex\Public
 */

use Zorvex\Core\Container;
use Zorvex\Core\Request;
use Zorvex\Core\Response;
use Zorvex\Core\Router;
use Zorvex\Infrastructure\Telegram\WebhookHandler;
use Zorvex\Presentation\Http\Api\AdminController;
use Zorvex\Presentation\Http\Api\MeController;
use Zorvex\Presentation\Http\Api\PaymentsController;
use Zorvex\Presentation\Http\Api\ProductsController;
use Zorvex\Presentation\Http\Api\SubscriptionsController;
use Zorvex\Presentation\Http\Api\ZarinpalCallbackController;

/** @var Container $container */
$container = require dirname(__DIR__) . '/bootstrap/app.php';

$request = Request::capture();
$router = new Router($container);

/*
 * Telegram
 */
$router->post('/webhook', WebhookHandler::class);

/*
 * System
 */
$router->get('/health', static function (): Response {
    return Response::json(json_ok(['status' => 'ok', 'time' => now()]));
});
$router->get('/', static function (): Response {
    return Response::json(json_ok([
        'name' => config('app.name', 'Zorvex'),
        'status' => 'running',
    ]));
});

/*
 * Pretty pages (nginx routes /miniapp & /admin through this entry point).
 */
$router->get('/miniapp', static function (): Response {
    require __DIR__ . '/app/index.php';
    return new Response();
});
$router->get('/miniapp.php', static function (): Response {
    require __DIR__ . '/app/index.php';
    return new Response();
});
$router->get('/admin', static function (): Response {
    require __DIR__ . '/admin.php';
    return new Response();
});
$router->get('/admin.php', static function (): Response {
    require __DIR__ . '/admin.php';
    return new Response();
});

/*
 * Mini-app API
 */
$router->get('/api/me', MeController::class);
$router->get('/api/products', ProductsController::class);
$router->get('/api/subscriptions', SubscriptionsController::class);
$router->post('/api/payments/start', [PaymentsController::class, 'start']);
$router->post('/api/payments/verify', [PaymentsController::class, 'verify']);
$router->get('/api/payments/history', [PaymentsController::class, 'history']);

/*
 * Payment callbacks
 */
$router->get('/payment/callback/zarinpal', ZarinpalCallbackController::class);

/*
 * Admin API
 */
$router->get('/api/admin/stats', [AdminController::class, 'stats']);
$router->get('/api/admin/servers', [AdminController::class, 'serversList']);
$router->post('/api/admin/servers', [AdminController::class, 'serverCreate']);
$router->put('/api/admin/servers/{id}', [AdminController::class, 'serverUpdate']);
$router->delete('/api/admin/servers/{id}', [AdminController::class, 'serverDelete']);
$router->get('/api/admin/servers/{id}/health', [AdminController::class, 'serverHealth']);
$router->get('/api/admin/users', [AdminController::class, 'usersList']);
$router->get('/api/admin/payments/pending', [AdminController::class, 'pendingPayments']);
$router->post('/api/admin/payments/{id}/approve', [AdminController::class, 'approvePayment']);
$router->post('/api/admin/payments/{id}/reject', [AdminController::class, 'rejectPayment']);

$response = $router->dispatch($request);
$response->send();