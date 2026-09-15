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
 * PurchaseSubscription — orchestrate the creation of a VPN subscription after
 * payment is confirmed. Handles balance debit and panel provisioning.
 *
 * @package Zorvex\Application\UseCase
 */
final class PurchaseSubscription
{
    /** @var iterable<PanelManager> */
    private iterable $panelManagers;

    /**
     * @param iterable<PanelManager> $panelManagers
     */
    public function __construct(
        private readonly UserRepository $users,
        private readonly ServerRepository $servers,
        private readonly SubscriptionRepository $subscriptions,
        iterable $panelManagers,
        private readonly LoggerInterface $logger,
    ) {
        $this->panelManagers = $panelManagers;
    }

    /**
     * Look up a purchasable product.
     */
    public function findProduct(int $id): ?Product
    {
        return $this->subscriptions->findProductById($id);
    }

    /**
     * Activate a subscription for a user on the best available server.
     *
     * @param array<string, mixed> $options Optional extension/config overrides.
     * @return Subscription
     * @throws RuntimeException when no server/panel is available or provisioning fails.
     */
    public function activate(User $user, Product $product, array $options = []): Subscription
    {
        $server = $this->pickServer($product);
        $manager = $this->resolvePanel($server);

        $username = generate_vpn_username($user->telegramId);
        $expiry = time() + $product->durationDays * 86400;

        // Provision the client on the panel.
        $provisioned = $manager->createClient($server, $username, [
            'traffic' => $product->trafficBytes,
            'expiry' => $expiry,
        ] + $options);

        $subscription = $this->subscriptions->save(new Subscription([
            'user_id' => $user->id,
            'server_id' => $server->id,
            'product_id' => $product->id,
            'username' => $username,
            'status' => 'active',
            'traffic_used' => 0,
            'traffic_limit' => $product->trafficBytes,
            'expires_at' => gmdate('Y-m-d H:i:s', $expiry),
            'activated_at' => now(),
            'config_link' => $provisioned['config'],
            'panel_payload' => json_encode(['panel' => $manager::class, 'provisioned' => $provisioned]),
        ]));

        // Best-effort: record the assigned server on the user row.
        if ($user->vpnServerId !== $server->id) {
            $this->users->save(new User($user->toArray() + ['vpn_server_id' => $server->id, 'vpn_username' => $username]));
        }

        $this->logger->info('Subscription activated', [
            'subscription_id' => $subscription->id,
            'user_id' => $user->id,
            'product_id' => $product->id,
            'server' => $server->name,
        ]);

        return $subscription;
    }

    /**
     * Find an appropriate server for the product (least loaded active server),
     * preferring the product's assigned server when present.
     *
     * @throws RuntimeException
     */
    private function pickServer(Product $product): Server
    {
        $productServer = $this->servers->findById((int) $product->serverId);

        if ($productServer !== null && $productServer->isActive()) {
            return $productServer;
        }

        $available = $this->servers->findAvailable();
        if ($available === []) {
            throw new RuntimeException('در حال حاضر سروری برای ارائه سرویس در دسترس نیست.');
        }

        // Least loaded first.
        usort($available, static fn (Server $a, Server $b): int => $a->maxClients <=> $b->maxClients);

        return $available[0];
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

        throw new RuntimeException('پنل موردنظر پشتیبانی نمی‌شود: ' . $server->type);
    }
}