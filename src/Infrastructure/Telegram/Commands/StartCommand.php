<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram\Commands;

use Zorvex\Application\UseCase\AuthenticateUser;
use Zorvex\Core\EventBus;
use Zorvex\Domain\Entity\User;
use Zorvex\Infrastructure\Telegram\Keyboards\MainKeyboard;
use Zorvex\Infrastructure\Telegram\TelegramBot;

/**
 * /start — welcome and quick registration.
 *
 * @package Zorvex\Infrastructure\Telegram\Commands
 */
final class StartCommand implements CommandInterface
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly AuthenticateUser $authenticate,
        private readonly MainKeyboard $mainKeyboard,
        private readonly EventBus $events,
    ) {
    }

    public function name(): string
    {
        return 'start';
    }

    public function handle(array $update): void
    {
        $message = $update['message'] ?? [];
        $chat = $message['chat'] ?? [];
        $from = $message['from'] ?? [];

        $chatId = (int) ($chat['id'] ?? 0);
        $telegramId = (int) ($from['id'] ?? 0);

        if ($chatId === 0 || $telegramId === 0) {
            return;
        }

        $user = $this->authenticate->fromTelegram($telegramId, [
            'username' => (string) ($from['username'] ?? ''),
            'first_name' => (string) ($from['first_name'] ?? ''),
            'last_name' => (string) ($from['last_name'] ?? ''),
        ]);

        // Extract a referral token passed as /start ref_XXXX (future affiliate use).
        $text = (string) ($message['text'] ?? '');
        if (preg_match('/^\/start\s+([A-Za-z0-9_]+)$/', $text, $m) === 1) {
            $this->events->dispatch('user:referral', [
                'telegram_id' => $telegramId,
                'referral' => $m[1],
            ]);
        }

        $text = $this->mainKeyboard->menuText($user->displayName());

        $this->bot->sendMessage($chatId, $text, [
            'reply_markup' => json_encode($this->mainKeyboard->mainMenu($this->isAdmin($user))),
            'parse_mode' => 'HTML',
        ]);
    }

    private function isAdmin(User $user): bool
    {
        $admins = (array) config('telegram.admin_ids', []);

        return in_array($user->telegramId, $admins, true);
    }
}