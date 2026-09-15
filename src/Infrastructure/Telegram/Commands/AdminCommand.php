<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram\Commands;

use Zorvex\Infrastructure\Telegram\TelegramBot;

/**
 * /admin — admin-only controls (stats and shortcuts).
 *
 * Access is restricted to the admin IDs configured in `telegram.admin_ids`.
 *
 * @package Zorvex\Infrastructure\Telegram\Commands
 */
final class AdminCommand implements CommandInterface
{
    public function __construct(
        private readonly TelegramBot $bot,
    ) {
    }

    public function name(): string
    {
        return 'admin';
    }

    public function handle(array $update): void
    {
        $message = $update['message'] ?? [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $from = $message['from'] ?? [];
        $telegramId = (int) ($from['id'] ?? 0);
        $text = (string) ($message['text'] ?? '');

        $admins = array_map('intval', (array) config('telegram.admin_ids', []));

        if ($chatId === 0 || $telegramId === 0 || !in_array($telegramId, $admins, true)) {
            if ($chatId !== 0) {
                $this->bot->sendMessage($chatId, '⛔️ شما مجاز به استفاده از این بخش نیستید.');
            }
            return;
        }

        $command = trim(strtolower($text));

        match ($command) {
            '/admin', '/admin stats', '/admin panel' => $this->stats($chatId),
            '/admin broadcast' => $this->bot->sendMessage($chatId, "از مینی‌اپ مدیریت، گزینه «پیام همگانی» را انتخاب کنید."),
            default => $this->help($chatId),
        };
    }

    private function stats(int $chatId): void
    {
        $users = (int) app(\Zorvex\Infrastructure\Database\MySQLUserRepository::class)->count();
        $servers = (int) app(\Zorvex\Infrastructure\Database\MySQLServerRepository::class)->count();
        $subscriptions = (int) app(\Zorvex\Infrastructure\Database\MySQLSubscriptionRepository::class)->count();
        $revenue = (float) app(\Zorvex\Infrastructure\Database\MySQLPaymentRepository::class)->totalRevenue();

        $text = "📊 <b>آمار کلی سیستم</b>\n\n"
            . "👥 کاربران: <b>" . fa_digits(number_format($users)) . "</b>\n"
            . "🖥 سرورها: <b>" . fa_digits(number_format($servers)) . "</b>\n"
            . "📦 اشتراک‌ها: <b>" . fa_digits(number_format($subscriptions)) . "</b>\n"
            . "💰 درآمد کل: <b>" . escape_html(format_price($revenue)) . "</b>\n";

        $this->bot->sendMessage($chatId, $text, ['parse_mode' => 'HTML']);
    }

    private function help(int $chatId): void
    {
        $this->bot->sendMessage($chatId, "⚙️ <b>دستورات مدیریت</b>\n\n"
            . "/admin stats — آمار کلی\n"
            . "/admin broadcast — پیام همگانی\n"
            . "برای مدیریت کامل به پنل ادمین مراجعه کنید.", [
            'parse_mode' => 'HTML',
        ]);
    }
}