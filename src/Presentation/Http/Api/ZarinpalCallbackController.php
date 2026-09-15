<?php

declare(strict_types=1);

namespace Zorvex\Presentation\Http\Api;

use Psr\Log\LoggerInterface;
use Zorvex\Application\UseCase\ProcessPayment;
use Zorvex\Core\Request;
use Zorvex\Core\Response;

/**
 * Handles the Zarinpal payment return (callback) endpoint.
 *
 * Zarinpal appends ?Status=OK&Authority=... to the callback URL after the user
 * completes (or cancels) the payment.
 *
 * @package Zorvex\Presentation\Http\Api
 */
final class ZarinpalCallbackController
{
    public function __construct(
        private readonly ProcessPayment $payments,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $authority = (string) $request->query('Authority', '');
        $status = (string) $request->query('Status', '');
        $orderId = (string) $request->query('order_id', '');

        if ($status === '' || strtolower($status) !== 'ok') {
            $this->logger->info('Zarinpal payment cancelled or failed', ['authority' => $authority]);

            return Response::html(
                '<html dir="rtl" lang="fa"><body style="font-family:sans-serif;background:#0f1222;color:#fff;display:grid;place-items:center;height:100vh;margin:0">'
                . '<div style="text-align:center"><h2>❌ پرداخت ناموفق بود</h2>'
                . '<p>می‌توانید از طریق ربات مجدداً تلاش کنید.</p>'
                . '<a href="tg://resolve?domain=' . escape_html((string) config('telegram.support_username', '')) . '">پشتیبانی</a></div></body></html>'
            );
        }

        if ($authority === '') {
            return Response::html('Invalid authority', 400);
        }

        try {
            $result = $this->payments->handleCallback($authority, [
                'order_id' => $orderId,
                'amount' => (int) $request->query('amount', 0),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('Zarinpal callback error', ['error' => $e->getMessage(), 'authority' => $authority]);

            return Response::html('Internal error', 500);
        }

        if ($result['status'] === 'completed') {
            return Response::html(
                '<html dir="rtl" lang="fa"><body style="font-family:sans-serif;background:#0f1222;color:#fff;display:grid;place-items:center;height:100vh;margin:0">'
                . '<div style="text-align:center"><h2>✅ پرداخت موفق بود</h2>'
                . '<p>اشتراک شما فعال شد. از ربات کانفیگ خود را دریافت کنید.</p>'
                . '<a href="tg://resolve?domain=' . escape_html((string) config('telegram.support_username', '')) . '">پشتیبانی</a></div></body></html>'
            );
        }

        return Response::html(
            '<html dir="rtl" lang="fa"><body style="font-family:sans-serif;background:#0f1222;color:#fff;display:grid;place-items:center;height:100vh;margin:0">'
            . '<div style="text-align:center"><h2>⏳ در انتظار بررسی پرداخت</h2>'
            . '<p>پس از تأیید، اشتراک شما فعال خواهد شد.</p></div></body></html>'
        );
    }
}