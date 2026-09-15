<?php

declare(strict_types=1);

namespace Zorvex\Application\UseCase;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Zorvex\Domain\Entity\Product;
use Zorvex\Domain\Entity\Server;
use Zorvex\Domain\Entity\Subscription;
use Zorvex\Domain\Entity\User;
use Zorvex\Domain\Repository\ServerRepository;
use Zorvex\Domain\Repository\SubscriptionRepository;
use Zorvex\Domain\Repository\UserRepository;
use Zorvex\Domain\Service\PanelManager;

/**
 * RenewSubscription — extend the expiry date and traffic of an active
 * subscription after the renewal payment clears.
 *
 * @package Zorvex\Application\UseCase
 */
final class RenewSubscription
{
    /** @var iterable<PanelManager> */
    private iterable $panelManagers;

    /**
     * @param iterable<PanelManager> $panelManagers
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly SubscriptionRepository $subscriptions,
        private readonly ServerRepository $servers,
        iterable $panelManagers,
        private readonly LoggerInterface $logger,
    ) {
        $this->panelManagers = $panelManagers;
    }

    /**
     * Renew an active subscription with the given product's duration/traffic.
     *
     * When the subscription is already expired this reactivates it.
     *
     * @throws RuntimeException when the subscription is not renewable locally.
     */
    public function renew(User $user, Subscription $subscription, Product $product): Subscription
    {
        $server = $this->servers->findById($subscription->serverId);
        if ($server === null) {
            throw new RuntimeException('سرور اشتراک دیگر در دسترس نیست.');
        }

        $manager = $this->resolvePanel($server);

        $baseTime = $subscription->hasExpired() ? time() : (strtotime((string) $subscription->expiresAt) ?: time());
        $newExpiry = $baseTime + $product->durationDays * 86400;

        $manager->modifyClient($server, $subscription->username, [
            'expire' => $newExpiry,
            'data_limit' => $product->trafficBytes,
        ]);

        $updated = $this->subscriptions->save(new Subscription($subscription->toArray() + [
            'status' => 'active',
            'traffic_limit' => $product->trafficBytes,
            'expires_at' => gmdate('Y-m-d H:i:s', $newExpiry),
            'product_id' => $product->id,
        ]));

        $this->logger->info('Subscription renewed', [
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'new_expiry' => $newExpiry,
        ]);

        return $updated;
    }

    /**
     * Find a panel manager that supports the given server.
     *
     * @throws RuntimeException
     */
    private function resolvePanel(Server $server): PanelManager
    {
        foreach ($this->panelManagers as $manager) {
            if ($manager->supports($server)) {
                return $manager;
            }
        }

        throw new RuntimeException('پنل موردنظر پشتیبانی نمی‌شود.');
    }
}