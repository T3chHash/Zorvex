<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram\Commands;

use Zorvex\Domain\Repository\SubscriptionRepository;
use Zorvex\Infrastructure\Telegram\Keyboards\ServiceKeyboard;
use Zorvex\Infrastructure\Telegram\TelegramBot;

/**
 * Services menu — list purchasable VPN packages.
 *
 * @package Zorvex\Infrastructure\Telegram\Commands
 */
final class ServicesCommand implements CommandInterface
{
    public function __construct(
        private readonly TelegramBot $bot,
        private readonly SubscriptionRepository $subscriptions,
        private readonly ServiceKeyboard $serviceKeyboard,
    ) {
    }

    public function name(): string
    {
        return 'services';
    }

    public function handle(array $update): void
    {
        $message = $update['message'] ?? [];
        $callback = $update['callback_query'] ?? [];

        $chatId = 0;
        $messageId = null;
        $queryId = null;

        if ($message !== []) {
            $chatId = (int) ($message['chat']['id'] ?? 0);
        } else {
            $chatId = (int) ($callback['message']['chat']['id'] ?? 0);
            $messageId = (int) ($callback['message']['message_id'] ?? 0);
            $queryId = (string) ($callback['id'] ?? '');
        }

        if ($chatId === 0) {
            return;
        }

        $products = $this->subscriptions->availableProducts();

        $text = $this->listText($products);
        $keyboard = json_encode($this->serviceKeyboard->services(
            array_map(static fn ($p) => $p->toArray(), $products)
        ));

        if ($messageId !== null) {
            $this->bot->editMessageText($chatId, $messageId, $text, [
                'reply_markup' => $keyboard,
                'parse_mode' => 'HTML',
            ]);
            if ($queryId !== null && $queryId !== '') {
                $this->bot->answerCallbackQuery($queryId);
            }
            return;
        }

        $this->bot->sendMessage($chatId, $text, [
            'reply_markup' => $keyboard,
            'parse_mode' => 'HTML',
        ]);
    }

    /**
     * @param list<object> $products
     */
    private function listText(array $products): string
    {
        if ($products === []) {
            return "محصولی برای نمایش موجود نیست. لطفاً بعداً دوباره تلاش کنید 🙏";
        }

        $lines = ["📦 <b>محصولات موجود</b>\n", "انتخاب کنید:"];
        foreach ($products as $product) {
            $lines[] = sprintf(
                "— <b>%s</b>: %s | %s روز | %s",
                escape_html((string) $product->name),
                escape_html(format_price((float) $product->price)),
                escape_html((string) $product->durationDays),
                escape_html((string) $product->trafficLabel())
            );
        }

        return implode("\n", $lines);
    }
}