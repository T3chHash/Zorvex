<?php

declare(strict_types=1);

namespace Zorvex\Presentation\Http\Api;

use Zorvex\Core\Request;
use Zorvex\Core\Response;
use Zorvex\Domain\Repository\SubscriptionRepository;
use Zorvex\Domain\Repository\UserRepository;

/**
 * List the authenticated user's subscriptions.
 *
 * @package Zorvex\Presentation\Http\Api
 */
final class SubscriptionsController
{
    public function __construct(
        private readonly UserRepository $users,
        private readonly SubscriptionRepository $subscriptions,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $user = $request->webAppUser();
        if ($user === null) {
            return Response::json(json_fail('داده ورود نامعتبر است.', 401), 401);
        }

        $entity = $this->users->findByTelegramId((int) ($user['id'] ?? 0));
        if ($entity === null) {
            return Response::json(json_ok(['subscriptions' => []]));
        }

        $list = $this->subscriptions->findByUser((int) $entity->id);

        return Response::json(json_ok([
            'subscriptions' => array_map(fn ($s) => $s->toArray(), $list),
        ]));
    }
}