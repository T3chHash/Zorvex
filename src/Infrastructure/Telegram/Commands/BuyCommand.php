<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram\Commands;

use Zorvex\Application\UseCase\PurchaseSubscription;
use Zorvex\Application\UseCase\ProcessPayment;
use Zorvex\Domain\Entity\Payment;
use Zorvex\Infrastructure\Database\MySQLPaymentRepository;
use Zorvex\Infrastructure\Telegram\Keyboards\PaymentKeyboard;
use Zorvex\Infrastructure\Telegram\Keyboards\ServiceKeyboard;
use Zorvex\Infrastructure\Telegram\TelegramBot;

/**
 * Buy flow — product selection, payment initiation and payment callbacks.
 *
 * Callback contract:
 *   buy:{productId}                 → show payment methods for a product
 *   pay:{productId}:{method}        → create an invoice and show payment actions
 *   pay:receipt:{orderId}           → ask user to upload a receipt
 *   pay:status:{orderId}            → check payment status
 *   pay:cancel:{orderId}            → cancel a pending payment
 *
 * @package Zorvex\Infrastructure\Telegram\Commands
 */
final class BuyCommand implements CommandInterface
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly PurchaseSubscription $purchase,
        private readonly ProcessPayment $processPayment,
        private readonly ServiceKeyboard $serviceKeyboard,
        private readonly PaymentKeyboard $paymentKeyboard,
    ) {
    }

    public function name(): string
    {
        return 'buy';
    }

    public function handle(array $update): void
    {
        $callback = $update['callback_query'] ?? [];
        if ($callback === []) {
            return;
        }

        $data = (string) ($callback['data'] ?? '');
        $from = $callback['from'] ?? [];
        $chatId = (int) ($callback['message']['chat']['id'] ?? 0);
        $messageId = (int) ($callback['message']['message_id'] ?? 0);
        $queryId = (string) ($callback['id'] ?? '');
        $telegramId = (int) ($from['id'] ?? 0);

        if ($chatId === 0 || $telegramId === 0) {
            return;
        }

        $segments = explode(':', $data);
        $action = $segments[0] ?? '';

        try {
            if ($action === 'buy') {
                $this->choosePayment($chatId, $messageId, $queryId, (int) ($segments[1] ?? 0));
                return;
            }

            if ($action === 'pay') {
                $sub = $segments[1] ?? '';

                if (is_numeric($sub)) {
                    $this->createInvoice($chatId, $messageId, $queryId, $telegramId, (int) $sub, (string) ($segments[2] ?? ''));
                    return;
                }

                match ($sub) {
                    'receipt' => $this->requestReceipt($chatId, $queryId, (string) ($segments[2] ?? '')),
                    'status'  => $this->paymentStatus($chatId, $queryId, (string) ($segments[2] ?? '')),
                    'cancel'  => $this->cancelPayment($chatId, $queryId, (string) ($segments[2] ?? '')),
                    default   => $this->bot->answerCallbackQuery($queryId, 'عملیات ناشناخته', true),
                };

                return;
            }

            $this->bot->answerCallbackQuery($queryId, 'عملیات ناشناخته', true);
        } catch (\Throwable $e) {
            logger()->error('Buy flow error', ['error' => $e->getMessage(), 'data' => $data]);
            $this->bot->answerCallbackQuery($queryId, 'خطا در انجام عملیات. لطفاً دوباره تلاش کنید.', true);
        }
    }

    private function choosePayment(int $chatId, int $messageId, string $queryId, int $productId): void
    {
        $product = $this->purchase->findProduct($productId);
        if ($product === null) {
            $this->bot->answerCallbackQuery($queryId, 'محصول یافت نشد.', true);
            return;
        }

        $methods = $this->paymentMethods();
        if ($methods === []) {
            $this->bot->answerCallbackQuery($queryId, 'روش پرداختی فعال نیست.', true);
            return;
        }

        $text = sprintf(
            "💠 <b>%s</b>\n"
            . "قیمت: <b>%s</b>\n"
            . "مدت: <b>%s روز</b>\n"
            . "حجم: <b>%s</b>\n\n"
            . "روش پرداخت را انتخاب کنید:",
            escape_html($product->name),
            escape_html(format_price($product->price)),
            (string) $product->durationDays,
            escape_html($product->trafficLabel())
        );

        $this->bot->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => json_encode($this->serviceKeyboard->payMethods($productId, $methods)),
            'parse_mode' => 'HTML',
        ]);

        $this->bot->answerCallbackQuery($queryId);
    }

    private function createInvoice(int $chatId, int $messageId, string $queryId, int $telegramId, int $productId, string $method): void
    {
        try {
            $invoice = $this->processPayment->prepare($telegramId, $productId, $method);
        } catch (\RuntimeException $e) {
            $this->bot->answerCallbackQuery($queryId, $e->getMessage(), true);
            return;
        }

        $text = "🧾 <b>صورتحساب ایجاد شد</b>\n\n"
            . "شناسه: <code>" . escape_html($invoice->uuid) . "</code>\n"
            . "مبلغ: <b>" . escape_html(format_price($invoice->amount)) . "</b>\n\n"
            . "جهت پرداخت، اطلاعات زیر را دنبال کنید:";

        $this->bot->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => json_encode($this->paymentKeyboard->paymentActions([
                'order_id' => $invoice->uuid,
            ])),
            'parse_mode' => 'HTML',
        ]);

        $this->bot->answerCallbackQuery($queryId, 'لطفاً پرداخت را انجام دهید.');
    }

    private function requestReceipt(int $chatId, string $queryId, string $uuid): void
    {
        $this->bot->answerCallbackQuery($queryId);
        $this->bot->sendMessage($chatId,
            "📷 <b>ارسال رسید</b>\n\n"
            . "لطفاً اسکرین‌شات رسید پرداخت خود را همین‌جا ارسال کنید.\n"
            . "پس از بررسی توسط تیم پشتیبانی، اشتراک شما فعال خواهد شد.", [
            'parse_mode' => 'HTML',
        ]);
    }

    private function paymentStatus(int $chatId, string $queryId, string $uuid): void
    {
        /** @var MySQLPaymentRepository $repo */
        $repo = app(MySQLPaymentRepository::class);
        $payment = $repo->findByOrderId($uuid);

        $labels = [
            'pending' => '🕐 در انتظار پرداخت',
            'processing' => '⚙️ در حال بررسی',
            'completed' => '✅ پرداخت موفق',
            'failed' => '❌ پرداخت ناموفق',
            'cancelled' => '🚫 لغو شده',
        ];

        $this->bot->answerCallbackQuery(
            $queryId,
            $payment === null ? 'پرداختی یافت نشد.' : ($labels[$payment->status] ?? $payment->status),
            $payment === null
        );
    }

    private function cancelPayment(int $chatId, string $queryId, string $uuid): void
    {
        /** @var MySQLPaymentRepository $repo */
        $repo = app(MySQLPaymentRepository::class);
        $payment = $repo->findByOrderId($uuid);

        if ($payment instanceof Payment && $payment->isPending()) {
            $saved = $repo->savePayment(new Payment([
                'id' => $payment->id,
                'user_id' => $payment->userId,
                'invoice_id' => $payment->invoiceId,
                'method' => $payment->method,
                'status' => 'cancelled',
                'amount' => $payment->amount,
                'currency' => $payment->currency,
                'order_id' => $payment->orderId,
                'external_ref' => $payment->externalRef,
                'proof_file' => $payment->proofFile,
                'meta' => $payment->meta,
                'created_at' => $payment->createdAt,
                'paid_at' => $payment->paidAt,
            ]));
        }

        $this->bot->answerCallbackQuery($queryId, 'پرداخت لغو شد.');
    }

    /**
     * @return list<array{id: string, label: string}>
     */
    private function paymentMethods(): array
    {
        $methods = [];

        if ((string) (config('payment.crypto.wallet') ?? '') !== '') {
            $methods[] = ['id' => 'crypto', 'label' => '💎 کریپتو (USDT / TRX)'];
        }
        if ((bool) (config('payment.card_to_card.enabled') ?? false)) {
            $methods[] = ['id' => 'card_to_card', 'label' => '🏦 کارت به کارت'];
        }
        if ((bool) (config('payment.zarinpal.enabled') ?? false)) {
            $methods[] = ['id' => 'zarinpal', 'label' => '🌐 درگاه زرین‌پال'];
        }

        return $methods;
    }
}