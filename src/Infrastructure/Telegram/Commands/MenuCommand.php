<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram\Commands;

use Zorvex\Application\UseCase\AuthenticateUser;
use Zorvex\Domain\Entity\User;
use Zorvex\Domain\Repository\SubscriptionRepository;
use Zorvex\Infrastructure\Telegram\Keyboards\MainKeyboard;
use Zorvex\Infrastructure\Telegram\TelegramBot;

/**
 * /menu + nav:* navigation hub.
 *
 * Handles:
 *   /menu                  main menu
 *   nav:home               main menu (edit in place)
 *   nav:my                 list my subscriptions
 *   nav:charge             balance / top-up info
 *   nav:support            support panel (forwarded by WebhookHandler)
 *
 * @package Zorvex\Infrastructure\Telegram\Commands
 */
final class MenuCommand implements CommandInterface
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly AuthenticateUser $authenticate,
        private readonly MainKeyboard $mainKeyboard,
        private readonly SubscriptionRepository $subscriptions,
    ) {
    }

    public function name(): string
    {
        return 'menu';
    }

    public function handle(array $update): void
    {
        $message = $update['message'] ?? [];
        if ($message !== []) {
            $this->renderMessage($message);
            return;
        }

        $callback = $update['callback_query'] ?? [];
        if ($callback !== []) {
            $this->handleCallback($callback);
        }
    }

    public function renderMessage(array $message): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $from = $message['from'] ?? [];
        $telegramId = (int) ($from['id'] ?? 0);

        if ($chatId === 0 || $telegramId === 0) {
            return;
        }

        $user = $this->authenticate->fromTelegram($telegramId, [
            'username' => (string) ($from['username'] ?? ''),
            'first_name' => (string) ($from['first_name'] ?? ''),
            'last_name' => (string) ($from['last_name'] ?? ''),
        ]);

        $this->bot->sendMessage($chatId, $this->mainKeyboard->menuText($user->displayName()), [
            'reply_markup' => json_encode($this->mainKeyboard->mainMenu($this->isAdmin($user))),
            'parse_mode' => 'HTML',
        ]);
    }

    public function handleCallback(array $callback): void
    {
        $data = (string) ($callback['data'] ?? '');
        $message = $callback['message'] ?? [];
        $chatId = (int) ($message['chat']['id'] ?? 0);
        $messageId = (int) ($message['message_id'] ?? 0);
        $from = $callback['from'] ?? [];
        $telegramId = (int) ($from['id'] ?? 0);
        $queryId = (string) ($callback['id'] ?? '');

        if ($chatId === 0 || $messageId === 0 || $telegramId === 0) {
            return;
        }

        $user = $this->authenticate->fromTelegram($telegramId);

        match ($data) {
            'nav:home' => $this->editMainMenu($chatId, $messageId, $user, $queryId),
            'nav:my' => $this->mySubscriptions($chatId, $messageId, $user, $queryId),
            'nav:charge' => $this->balanceInfo($chatId, $messageId, $user, $queryId),
            default => $this->bot->answerCallbackQuery($queryId, 'لطفاً از خود ربات استفاده کنید.', true),
        };
    }

    private function editMainMenu(int $chatId, int $messageId, User $user, string $queryId): void
    {
        $this->bot->editMessageText($chatId, $messageId, $this->mainKeyboard->menuText($user->displayName()), [
            'reply_markup' => json_encode($this->mainKeyboard->mainMenu($this->isAdmin($user))),
            'parse_mode' => 'HTML',
        ]);

        if ($queryId !== '') {
            $this->bot->answerCallbackQuery($queryId);
        }
    }

    private function mySubscriptions(int $chatId, int $messageId, User $user, string $queryId): void
    {
        $list = $this->subscriptions->findByUser((int) $user->id);

        if ($list === []) {
            $text = "📦 <b>اشتراک‌های شما</b>\n\nهنوز اشتراکی ندارید. "
                . "برای خرید از منوی اصلی گزینه «خرید اشتراک» را انتخاب کنید.";
        } else {
            $lines = ["📦 <b>اشتراک‌های شما</b>\n"];
            foreach ($list as $sub) {
                $status = match ($sub->status) {
                    'active' => '✅ فعال',
                    'expired' => '⛔️ منقضی',
                    'suspended' => '🚫 معلق',
                    default => $sub->status,
                };
                $expiry = $sub->expiresAt !== null ? gmdate('Y/m/d', strtotime($sub->expiresAt)) : '—';
                $lines[] = sprintf(
                    "— <b>@%s</b> | %s\n  حجم: %s | انقضا: %s",
                    escape_html($sub->username),
                    $status,
                    escape_html(format_bytes($sub->trafficLimit)),
                    escape_html((string) $expiry)
                );
            }
            $text = implode("\n", $lines);
        }

        $keyboard = ['inline_keyboard' => [[
            ['text' => '🔙 بازگشت به منو', 'callback_data' => 'nav:home'],
        ]]];

        $this->bot->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => json_encode($keyboard),
            'parse_mode' => 'HTML',
        ]);

        if ($queryId !== '') {
            $this->bot->answerCallbackQuery($queryId);
        }
    }

    private function balanceInfo(int $chatId, int $messageId, User $user, string $queryId): void
    {
        $text = "💰 <b>موجودی کیف پول</b>\n\n"
            . "موجودی فعلی شما: <b>" . escape_html(format_price($user->balance)) . "</b>\n\n"
            . "برای افزایش موجودی، از طریق پشتیبانی پیام دهید یا روش پرداخت را انتخاب کنید.";

        $this->bot->editMessageText($chatId, $messageId, $text, [
            'reply_markup' => json_encode(['inline_keyboard' => [[
                ['text' => '🛒 خرید مستقیم', 'callback_data' => 'nav:services'],
                ['text' => '🔙 منو', 'callback_data' => 'nav:home'],
            ]]]),
            'parse_mode' => 'HTML',
        ]);

        if ($queryId !== '') {
            $this->bot->answerCallbackQuery($queryId);
        }
    }

    private function isAdmin(User $user): bool
    {
        return in_array($user->telegramId, (array) config('telegram.admin_ids', []), true);
    }
}