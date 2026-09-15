<?php

declare(strict_types=1);

namespace Zorvex\Presentation\Http\Api;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Zorvex\Application\UseCase\ProcessPayment;
use Zorvex\Core\Request;
use Zorvex\Core\Response;
use Zorvex\Domain\Repository\PaymentRepository;
use Zorvex\Domain\Repository\UserRepository;

/**
 * Mini-app payment endpoints.
 *
 *   POST /api/payments/start   {product_id, method} → initiates a payment
 *   POST /api/payments/verify  {order_id}           → re-checks status
 *   GET  /api/payments/history                     → payment history
 *
 * @package Zorvex\Presentation\Http\Api
 */
final class PaymentsController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly PaymentRepository $payments,
        private readonly ProcessPayment $process,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function start(Request $request): Response
    {
        $user = $request->webAppUser();
        if ($user === null) {
            return Response::json(json_fail('داده ورود نامعتبر است.', 401), 401);
        }

        $telegramId = (int) ($user['id'] ?? 0);
        $productId = (int) $request->input('product_id', 0);
        $method = (string) $request->input('method', '');

        if ($productId <= 0 || $method === '') {
            return Response::json(json_fail('محصول یا روش پرداخت نامعتبر است.'));
        }

        try {
            $invoice = $this->process->prepare($telegramId, $productId, $method);
        } catch (\Throwable $e) {
            $this->logger->warning('Mini-app payment start failed', ['error' => $e->getMessage()]);
            return Response::json(json_fail($e instanceof RuntimeException ? $e->getMessage() : 'خطا در ایجاد پرداخت.', 422), 422);
        }

        return Response::json(json_ok([
            'invoice' => $invoice->toArray(),
        ]));
    }

    public function verify(Request $request): Response
    {
        $user = $request->webAppUser();
        if ($user === null) {
            return Response::json(json_fail('داده ورود نامعتبر است.', 401), 401);
        }

        $orderId = (string) $request->input('order_id', '');
        if ($orderId === '') {
            return Response::json(json_fail('شناسه پرداخت ارسال نشده است.'));
        }

        $entity = $this->users->findByTelegramId((int) ($user['id'] ?? 0));
        if ($entity === null) {
            return Response::json(json_fail('کاربر یافت نشد.', 404), 404);
        }

        $payment = $this->payments->findByOrderId($orderId);
        if ($payment === null || $payment->userId !== $entity->id) {
            return Response::json(json_fail('پرداخت یافت نشد.', 404), 404);
        }

        return Response::json(json_ok(['status' => $payment->status]));
    }

    public function history(Request $request): Response
    {
        $user = $request->webAppUser();
        if ($user === null) {
            return Response::json(json_fail('داده ورود نامعتبر است.', 401), 401);
        }

        $entity = $this->users->findByTelegramId((int) ($user['id'] ?? 0));
        if ($entity === null) {
            return Response::json(json_ok(['payments' => []]));
        }

        $list = $this->payments->paymentsByUser((int) $entity->id, 50);

        return Response::json(json_ok([
            'payments' => array_map(fn ($p) => $p->toArray(), $list),
        ]));
    }
}