<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram\Commands;

use Zorvex\Infrastructure\Telegram\Keyboards\ServiceKeyboard;
use Zorvex\Infrastructure\Telegram\TelegramBot;

/**
 * /support — point users to the support handle and mini-app FAQ.
 *
 * @package Zorvex\Infrastructure\Telegram\Commands
 */
final class SupportCommand implements CommandInterface
{
    private ?\Zorvex\Domain\Entity\User $resolvedUser = null;

    public function __construct(
        private readonly TelegramBot $bot,
        private readonly ServiceKeyboard $keyboard,
    ) {
    }

    public function name(): string
    {
        return 'support';
    }

    public function handle(array $update): void
    {
        $message = $update['message'] ?? [];
        $callback = $update['callback_query'] ?? [];

        if ($message !== []) {
            $this->renderMessage($message);
            return;
        }

        if ($callback !== []) {
            $this->renderCallback($callback);
        }
    }

    public function renderMessage(array $message): void
    {
        $chatId = (int) ($message['chat']['id'] ?? 0);
        if ($chatId === 0) {
            return;
        }

        $this->bot->sendMessage($chatId, $this->supportText(), [
            'reply_markup' => json_encode($this->keyboard->backHome()),
            'parse_mode' => 'HTML',
        ]);
    }

    public function renderCallback(array $callback): void
    {
        $chatId = (int) ($callback['message']['chat']['id'] ?? 0);
        $queryId = (string) ($callback['id'] ?? '');

        if ($chatId === 0) {
            return;
        }

        $this->bot->sendMessage($chatId, $this->supportText(), [
            'reply_markup' => json_encode($this->keyboard->backHome()),
            'parse_mode' => 'HTML',
        ]);

        if ($queryId !== '') {
            $this->bot->answerCallbackQuery($queryId);
        }
    }

    private function supportText(): string
    {
        $supportUsername = (string) config('telegram.support_username', 'zorvex_support');

        return "📞 <b>پشتیبانی Zorvex</b>\n\n"
            . "برای دریافت راهنمایی، مشاوره یا پیگیری مشکلات می‌توانید به "
            . "ساپورت ما پیام دهید:\n\n"
            . "🆔 تیم پشتیبانی: @" . escape_html($supportUsername) . "\n\n"
            . "همچنین می‌توانید از منوی زیر با ما در ارتباط باشید.";
    }
}