<?php

declare(strict_types=1);

namespace Zorvex\Presentation\Http\Api;

use Zorvex\Core\Request;
use Zorvex\Core\Response;
use Zorvex\Domain\Repository\SubscriptionRepository;

/**
 * List available VPN products for the mini-app shop.
 *
 * @package Zorvex\Presentation\Http\Api
 */
final class ProductsController
{
    public function __construct(private readonly SubscriptionRepository $subscriptions)
    {
    }

    public function __invoke(Request $request): Response
    {
        $products = $this->subscriptions->availableProducts();

        return Response::json(json_ok([
            'products' => array_map(fn ($p) => $p->toArray(), $products),
        ]));
    }
}