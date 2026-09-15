<?php

declare(strict_types=1);

namespace Zorvex\Presentation\Http\Api;

use Zorvex\Core\Request;
use Zorvex\Core\Response;

/**
 * Mini-app identity endpoint.
 *
 * Returns the authenticated Telegram user when the request carries valid
 * web_app init data signed by Telegram secrets.
 *
 * @package Zorvex\Presentation\Http\Api
 */
final class MeController
{
    public function __invoke(Request $request): Response
    {
        $user = $request->webAppUser();

        if ($user === null) {
            return Response::json(json_fail('توکن نا‌معتبر است. لطفاً دوباره از ربات وارد شوید.', 401), 401);
        }

        $telegramId = (int) ($user['id'] ?? 0);
        if ($telegramId <= 0) {
            return Response::json(json_fail('داده ورود نامعتبر است.', 401), 401);
        }

        $repo = app(\Zorvex\Infrastructure\Database\MySQLUserRepository::class);
        $entity = $repo->findByTelegramId($telegramId)
            ?? $repo->findOrCreateTelegramUser($telegramId, [
                'username' => (string) ($user['username'] ?? ''),
                'first_name' => (string) ($user['first_name'] ?? ''),
                'last_name' => (string) ($user['last_name'] ?? ''),
            ]);

        return Response::json(json_ok([
            'id' => $entity->id,
            'telegram_id' => $entity->telegramId,
            'username' => $entity->username,
            'first_name' => $entity->firstName,
            'last_name' => $entity->lastName,
            'balance' => $entity->balance,
            'is_admin' => in_array($entity->telegramId, array_map('intval', (array) config('telegram.admin_ids', [])), true),
            'is_banned' => $entity->isBanned(),
        ]));
    }
}