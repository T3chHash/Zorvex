<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Telegram;

use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Telegram Bot API wrapper.
 *
 * Thin, typed convenience over the REST endpoint `https://api.telegram.org`.
 * All requests are JSON and signed with the bot token.
 *
 * @package Zorvex\Infrastructure\Telegram
 */
final class TelegramBot
{
    private const API = 'https://api.telegram.org/bot';

    public function __construct(
        private readonly string $token,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Send a text message.
     *
     * @param array<string, mixed> $extra Extra optional payload (reply_markup, parse_mode, ...).
     * @return array<string, mixed>
     */
    public function sendMessage(int $chatId, string $text, array $extra = []): array
    {
        return $this->call('sendMessage', [
            'chat_id' => $chatId,
            'text' => $text,
        ] + $extra);
    }

    /**
     * Edit an existing message.
     *
     * @return array<string, mixed>
     */
    public function editMessageText(int $chatId, int $messageId, string $text, array $extra = []): array
    {
        return $this->call('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $text,
        ] + $extra);
    }

    /**
     * Answer a callback query (show toast + optional alert).
     */
    public function answerCallbackQuery(string $callbackQueryId, string $text = '', bool $alert = false): array
    {
        return $this->call('answerCallbackQuery', [
            'callback_query_id' => $callbackQueryId,
            'text' => $text,
            'show_alert' => $alert,
        ]);
    }

    /**
     * Delete a message.
     */
    public function deleteMessage(int $chatId, int $messageId): array
    {
        return $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
    }

    /**
     * Send a chat action (typing, upload_photo, ...).
     */
    public function sendChatAction(int $chatId, string $action = 'typing'): array
    {
        return $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
    }

    /**
     * Send an HTML document.
     */
    public function sendDocument(int $chatId, string $filePath, string $caption = '', array $extra = []): array
    {
        $multipart = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'document' => new \CURLFile($filePath),
        ] + $extra;

        return $this->callMultipart('sendDocument', $multipart);
    }

    /**
     * Send a photo.
     */
    public function sendPhoto(int $chatId, string $photoPath, string $caption = '', array $extra = []): array
    {
        $multipart = [
            'chat_id' => $chatId,
            'caption' => $caption,
            'photo' => new \CURLFile($photoPath),
        ] + $extra;

        return $this->callMultipart('sendPhoto', $multipart);
    }

    /**
     * Send a Telegram web app mini-app keyboard button.
     */
    public function sendWebAppMessage(int $chatId, string $text, string $webAppUrl, string $buttonText = '📱', array $extra = []): array
    {
        return $this->sendMessage($chatId, $text, [
            'reply_markup' => json_encode([
                'inline_keyboard' => [[[
                    'text' => $buttonText,
                    'web_app' => ['url' => $webAppUrl],
                ]]],
            ]),
        ] + $extra);
    }

    /**
     * Get bot identity (used for diagnostics / /help).
     *
     * @return array<string, mixed>
     */
    public function getMe(): array
    {
        return $this->call('getMe');
    }

    /**
     * Set the webhook URL.
     */
    public function setWebhook(string $url, array $extra = []): array
    {
        $result = $this->call('setWebhook', ['url' => $url] + $extra);
        $this->logger->info('Webhook registered', ['url' => $url]);

        return $result;
    }

    /**
     * Remove the current webhook.
     */
    public function deleteWebhook(): array
    {
        return $this->call('deleteWebhook');
    }

    /**
     * Perform a JSON API call.
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     * @throws RuntimeException on transport/API error.
     */
    public function call(string $method, array $params): array
    {
        $url = self::API . $this->token . '/' . $method;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $decoded = $body !== false ? json_decode((string) $body, true) : null;

        if ($status >= 400 || $decoded === null || !isset($decoded['ok'])) {
            $description = isset($decoded['description']) ? (string) $decoded['description'] : ($error ?: (string) $body);
            $this->logger->error('Telegram API error', [
                'method' => $method,
                'status' => $status,
                'description' => $description,
            ]);

            throw new RuntimeException("Telegram API error on {$method}: {$description}", $status);
        }

        if ($decoded['ok'] !== true) {
            throw new RuntimeException('Telegram API returned ok:false for ' . $method);
        }

        return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
    }

    /**
     * Perform a multipart (file upload) call.
     *
     * @param array<string, mixed> $fields
     * @return array<string, mixed>
     */
    private function callMultipart(string $method, array $fields): array
    {
        $url = self::API . $this->token . '/' . $method;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $fields,
        ]);

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $decoded = $body !== false ? json_decode((string) $body, true) : null;

        if ($status >= 400 || $decoded === null || ($decoded['ok'] ?? false) !== true) {
            $description = is_array($decoded) ? (string) ($decoded['description'] ?? 'unknown error') : ($error ?: (string) $body);
            $this->logger->error('Telegram upload error', ['method' => $method, 'status' => $status, 'description' => $description]);

            throw new RuntimeException("Telegram upload error on {$method}: {$description}", $status);
        }

        return is_array($decoded['result'] ?? null) ? $decoded['result'] : [];
    }
}