<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram;

use Psr\Log\LoggerInterface;
use Zorvex\Core\EventBus;
use Zorvex\Core\Request;
use Zorvex\Core\Response;
use Zorvex\Infrastructure\Telegram\Commands\AdminCommand;
use Zorvex\Infrastructure\Telegram\Commands\BuyCommand;
use Zorvex\Infrastructure\Telegram\Commands\CommandInterface;
use Zorvex\Infrastructure\Telegram\Commands\MenuCommand;
use Zorvex\Infrastructure\Telegram\Commands\ServicesCommand;
use Zorvex\Infrastructure\Telegram\Commands\StartCommand;
use Zorvex\Infrastructure\Telegram\Commands\SupportCommand;

/**
 * Webhook entry-point handler.
 *
 * Receives raw Telegram updates, authenticates the sender, enforces a per-chat
 * rate limit, dispatches text commands and callback queries to the correct
 * command class, and emits lifecycle events on the EventBus.
 *
 * @package Zorvex\Infrastructure\Telegram
 */
final class WebhookHandler
{
    /** @var array<string, CommandInterface> */
    private array $commands = [];

    /** @var array<int, float> Last-command timestamp per chat. */
    private array $rateLimitLog = [];

    public function __construct(
        private readonly TelegramBot $bot,
        private readonly WebhookRateLimiter $rateLimiter,
        private readonly EventBus $events,
        private readonly LoggerInterface $logger,
        private readonly Authenticator $authenticator,
    ) {
    }

    /**
     * Register a command (called by the container bootstrap).
     */
    public function register(CommandInterface|string $command): void
    {
        $instance = $command instanceof CommandInterface ? $command : $this->resolve($command);
        $this->commands[$instance->name()] = $instance;
    }

    /**
     * Allow the webhook handler to act directly as a route handler.
     */
    public function __invoke(Request $request): Response
    {
        return $this->handleUpdate($request);
    }

    /**
     * Handle an update delivered through the webhook.
     */
    public function handleUpdate(Request $request): Response
    {
        $update = $request->update();

        // Unexpected payloads are safe to 200 (Telegram will retry otherwise).
        if ($update === [] || !$this->looksLikeUpdate($update)) {
            return Response::json(json_ok(['status' => 'ignored']), 200);
        }

        try {
            $this->dispatch($update);
        } catch (\Throwable $e) {
            $this->logger->critical('Unhandled webhook exception', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return Response::json(json_ok(['status' => 'ok']), 200);
    }

    /**
     * Dispatch a single update to the matching command.
     */
    private function dispatch(array $update): void
    {
        $text = $this->extractText($update);
        $chatId = $this->extractChatId($update);
        $from = $this->extractFrom($update);

        // Rate limit per chat (3 seconds between commands).
        if ($chatId > 0 && !$this->rateLimiter->allow($chatId)) {
            return;
        }

        // Authenticate the sender (creates / loads the user).
        $telegramId = (int) ($from['id'] ?? 0);
        if ($telegramId > 0) {
            $this->authenticator->identify($telegramId, $from);
        }

        $this->events->dispatch('update:received', $update);

        if ($this->isCallback($update)) {
            $this->dispatchCallback($update, $chatId);
            return;
        }

        $this->dispatchMessage($update, $text, $chatId);

        $this->events->dispatch('update:processed', ['chat_id' => $chatId, 'text' => $text]);
    }

    private function dispatchMessage(array $update, string $text, int $chatId): void
    {
        // Direct slash commands.
        if (preg_match('/^\/\S+/', $text) === 1) {
            $first = strtok($text, ' ');
            $command = strtolower((string) preg_replace('/[^a-z_0-9]/', '', $first !== false ? $first : $text));
            $handler = $this->commands[$command] ?? null;

            if ($handler !== null) {
                $handler->handle($update);
                return;
            }
        }

        // Anything else becomes a hint to use the menu.
        if ($chatId > 0) {
            $this->bot->sendMessage(
                $chatId,
                "لطفاً از دستورات زیر استفاده کنید:\n"
                . "/start — شروع مجدد\n"
                . "/menu — منوی اصلی\n"
                . "/services — محصولات\n"
                . "/support — پشتیبانی",
            );
        }
    }

    private function dispatchCallback(array $update, int $chatId): void
    {
        $callback = $update['callback_query'] ?? [];
        $data = (string) ($callback['data'] ?? '');

        $prefix = strtok($data, ':') ?: '';

        // Route callbacks based on their first segment. The `nav` hub also
        // forwards nav:services → services and nav:support → support.
        $handler = match (true) {
            in_array($prefix, ['buy', 'pay', 'confirm'], true) => $this->commands['buy'] ?? null,
            $prefix === 'services' => $this->commands['services'] ?? null,
            $prefix === 'support' => $this->commands['support'] ?? null,
            $prefix === 'admin' => $this->commands['admin'] ?? null,
            $data === 'nav:services' => $this->commands['services'] ?? null,
            $data === 'nav:support' => $this->commands['support'] ?? null,
            $prefix === 'nav' => $this->commands['menu'] ?? null,   // nav:home / nav:my / nav:charge
            default => null,
        };

        if ($handler !== null) {
            $handler->handle($update);
            return;
        }

        // Acknowledge unknown callbacks so the button doesn't spin.
        $queryId = (string) ($callback['id'] ?? '');
        if ($queryId !== '') {
            $this->bot->answerCallbackQuery($queryId);
        }
    }

    private function extractText(array $update): string
    {
        if (isset($update['message']['text'])) {
            return (string) $update['message']['text'];
        }

        if (isset($update['callback_query']['data'])) {
            return (string) $update['callback_query']['data'];
        }

        return '';
    }

    private function extractChatId(array $update): int
    {
        if (isset($update['message']['chat']['id'])) {
            return (int) $update['message']['chat']['id'];
        }

        if (isset($update['callback_query']['message']['chat']['id'])) {
            return (int) $update['callback_query']['message']['chat']['id'];
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function extractFrom(array $update): array
    {
        return $update['message']['from'] ?? $update['callback_query']['from'] ?? [];
    }

    private function isCallback(array $update): bool
    {
        return isset($update['callback_query']);
    }

    private function looksLikeUpdate(array $update): bool
    {
        return isset($update['update_id'])
            || isset($update['message'])
            || isset($update['callback_query'])
            || isset($update['my_chat_member'])
            || isset($update['pre_checkout_query'])
            || isset($update['successful_payment']);
    }

    private function resolve(string $class): CommandInterface
    {
        return app($class);
    }
}