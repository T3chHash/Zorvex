<?php

declare(strict_types=1);

namespace Zorvex\Presentation\Http\Api;

use RuntimeException;
use Zorvex\Application\UseCase\ManageServer;
use Zorvex\Core\Request;
use Zorvex\Core\Response;
use Zorvex\Domain\Repository\PaymentRepository;
use Zorvex\Domain\Repository\ServerRepository;
use Zorvex\Domain\Repository\SubscriptionRepository;
use Zorvex\Domain\Repository\UserRepository;

/**
 * Admin panel JSON API.
 *
 * All endpoints are protected by a validation that the caller is a configured
 * admin OR has a valid web_app init data whose Telegram ID is admin.
 *
 * @package Zorvex\Presentation\Http\Api
 */
final class AdminController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly ServerRepository $servers,
        private readonly SubscriptionRepository $subscriptions,
        private readonly PaymentRepository $payments,
        private readonly ManageServer $manageServer,
    ) {
    }

    public function stats(Request $request): Response
    {
        if (!$this->guard($request)) {
            return $this->denied();
        }

        return Response::json(json_ok([
            'users' => $this->users->count(),
            'active_users' => $this->users->countByStatus('active'),
            'servers' => $this->servers->count(),
            'subscriptions' => $this->subscriptions->count(),
            'active_subscriptions' => $this->subscriptions->countActive(),
            'payments' => $this->payments->countPayments(),
            'revenue' => $this->payments->totalRevenue(),
        ]));
    }

    public function serversList(Request $request): Response
    {
        if (!$this->guard($request)) {
            return $this->denied();
        }

        return Response::json(json_ok([
            'servers' => array_map(fn ($s) => $s->toArray(), $this->servers->all()),
        ]));
    }

    public function serverCreate(Request $request): Response
    {
        if (!$this->guard($request)) {
            return $this->denied();
        }

        try {
            $server = $this->manageServer->create($request->body());
            return Response::json(json_ok(['server' => $server->toArray()]), 201);
        } catch (RuntimeException $e) {
            return Response::json(json_fail($e->getMessage(), 422), 422);
        }
    }

    public function serverUpdate(Request $request, array $params): Response
    {
        if (!$this->guard($request)) {
            return $this->denied();
        }

        $server = $this->manageServer->update((int) ($params['id'] ?? 0), $request->body());
        if ($server === null) {
            return Response::json(json_fail('سرور یافت نشد.', 404), 404);
        }

        return Response::json(json_ok(['server' => $server->toArray()]));
    }

    public function serverDelete(Request $request, array $params): Response
    {
        if (!$this->guard($request)) {
            return $this->denied();
        }

        $ok = $this->manageServer->delete((int) ($params['id'] ?? 0));

        return $ok
            ? Response::json(json_ok(['deleted' => true]))
            : Response::json(json_fail('سرور یافت نشد.', 404), 404);
    }

    public function serverHealth(Request $request, array $params): Response
    {
        if (!$this->guard($request)) {
            return $this->denied();
        }

        return Response::json(json_ok([
            'healthy' => $this->manageServer->healthCheck((int) ($params['id'] ?? 0)),
        ]));
    }

    public function usersList(Request $request): Response
    {
        if (!$this->guard($request)) {
            return $this->denied();
        }

        $page = max(1, (int) $request->input('page', 1));
        $limit = min(100, max(1, (int) $request->input('limit', 20)));

        $result = $this->users->paginate($page, $limit, [
            'q' => (string) $request->input('q', ''),
            'status' => (string) $request->input('status', ''),
        ]);

        return Response::json(json_ok([
            'items' => array_map(fn ($u) => $u->toArray(), $result['items']),
            'total' => $result['total'],
            'page' => $page,
        ]));
    }

    public function pendingPayments(Request $request): Response
    {
        if (!$this->guard($request)) {
            return $this->denied();
        }

        return Response::json(json_ok([
            'payments' => array_map(fn ($p) => $p->toArray(), $this->payments->paymentsPending()),
        ]));
    }

    public function approvePayment(Request $request, array $params): Response
    {
        if (!$this->guard($request)) {
            return $this->denied();
        }

        $payments = app(\Zorvex\Infrastructure\Database\MySQLPaymentRepository::class);
        $payment = $payments->findPaymentById((int) ($params['id'] ?? 0));

        if ($payment === null) {
            return Response::json(json_fail('پرداخت یافت نشد.', 404), 404);
        }

        $process = app(\Zorvex\Application\UseCase\ProcessPayment::class);
        try {
            $process->confirm($payment->orderId, $request->string('reference', ''));
        } catch (RuntimeException $e) {
            return Response::json(json_fail($e->getMessage(), 422), 422);
        }

        return Response::json(json_ok(['status' => 'completed']));
    }

    public function rejectPayment(Request $request, array $params): Response
    {
        if (!$this->guard($request)) {
            return $this->denied();
        }

        $payments = app(\Zorvex\Infrastructure\Database\MySQLPaymentRepository::class);
        $payment = $payments->findPaymentById((int) ($params['id'] ?? 0));

        if ($payment === null) {
            return Response::json(json_fail('پرداخت یافت نشد.', 404), 404);
        }

        $process = app(\Zorvex\Application\UseCase\ProcessPayment::class);
        $process->fail($payment->orderId, $request->string('reason', 'rejected by admin'));

        return Response::json(json_ok(['status' => 'failed']));
    }

    /**
     * Confirm the requester is a configured admin (web_app id or Basic header).
     */
    private function guard(Request $request): bool
    {
        $admins = array_map('intval', (array) config('telegram.admin_ids', []));

        // Primary: web_app init data.
        $user = $request->webAppUser();
        if ($user !== null && in_array((int) ($user['id'] ?? 0), $admins, true)) {
            return true;
        }

        // Fallback for the /admin.php legacy panel: session token.
        $sessionToken = (string) ($request->query('token') ?? $request->header('X-Admin-Token', ''));
        $expected = sha1((string) config('app.key', 'zorvex') . ':admin');
        if ($sessionToken !== '' && hash_equals($expected, $sessionToken)) {
            return true;
        }

        return false;
    }

    private function denied(): Response
    {
        return Response::json(json_fail('دسترسی غیرمجاز.', 403), 403);
    }
}