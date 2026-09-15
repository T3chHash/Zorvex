<?php

declare(strict_types=1);

namespace Zorvex\Application\UseCase;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Zorvex\Domain\Entity\Server;
use Zorvex\Domain\Repository\ServerRepository;
use Zorvex\Domain\Repository\SubscriptionRepository;
use Zorvex\Domain\Service\PanelManager;

/**
 * ManageServer — CRUD and panel administration for VPN servers, plus
 * operational health checks and client lifecycle used by the admin UI.
 *
 * @package Zorvex\Application\UseCase
 */
final class ManageServer
{
    /** @var iterable<PanelManager> */
    private iterable $panelManagers;

    /**
     * @param iterable<PanelManager> $panelManagers
     */
    public function __construct(
        private readonly ServerRepository $servers,
        private readonly SubscriptionRepository $subscriptions,
        iterable $panelManagers,
        private readonly LoggerInterface $logger,
    ) {
        $this->panelManagers = $panelManagers;
    }

    public function all(): array
    {
        return $this->servers->all();
    }

    public function find(int $id): ?Server
    {
        return $this->servers->findById($id);
    }

    public function create(array $data): Server
    {
        $server = new Server([
            'name' => (string) ($data['name'] ?? ''),
            'url' => rtrim((string) ($data['url'] ?? ''), '/'),
            'type' => (string) ($data['type'] ?? 'marzban'),
            'api_key' => (string) ($data['api_key'] ?? ''),
            'status' => 'active',
            'subnet' => (string) ($data['subnet'] ?? ''),
            'max_clients' => (int) ($data['max_clients'] ?? 0),
        ]);

        if ($server->name === '' || $server->url === '' || $server->apiKey === '') {
            throw new RuntimeException('نام، آدرس و کلید API سرور الزامی است.');
        }

        $saved = $this->servers->save($server);
        $this->logger->info('Server created', ['server' => $saved->name, 'type' => $saved->type]);

        return $saved;
    }

    public function update(int $id, array $data): ?Server
    {
        $server = $this->servers->findById($id);
        if ($server === null) {
            return null;
        }

        $updated = $this->servers->save(new Server($server->toArray() + [
            'name' => (string) ($data['name'] ?? $server->name),
            'url' => rtrim((string) ($data['url'] ?? $server->url), '/'),
            'type' => (string) ($data['type'] ?? $server->type),
            'api_key' => (string) ($data['api_key'] ?? $server->apiKey),
            'status' => (string) ($data['status'] ?? $server->status),
            'subnet' => (string) ($data['subnet'] ?? $server->subnet ?? ''),
            'max_clients' => (int) ($data['max_clients'] ?? $server->maxClients),
        ]));

        $this->logger->info('Server updated', ['server' => $server->id]);

        return $updated;
    }

    public function delete(int $id): bool
    {
        $server = $this->servers->findById($id);
        if ($server === null) {
            return false;
        }

        // Revoke all subscriptions before removing the panel record.
        $this->subscriptions->revokeByServer($id);

        $this->logger->info('Server deleted', ['server' => $id]);
        return $this->servers->delete($id);
    }

    /**
     * Test health of a single server against its panel API.
     */
    public function healthCheck(int $id): bool
    {
        $server = $this->servers->findById($id);
        if ($server === null) {
            return false;
        }

        $manager = $this->resolvePanel($server);

        return $manager->healthCheck($server);
    }

    /**
     * Pull fresh usage data for a client from the panel and persist it.
     *
     * @param array<string, mixed> $subscription
     * @return array<string, mixed>
     */
    public function refreshClientUsage(array $subscription): array
    {
        $server = $this->servers->findById((int) ($subscription['server_id'] ?? 0));
        $username = (string) ($subscription['username'] ?? '');

        if ($server === null || $username === '') {
            return [];
        }

        try {
            $manager = $this->resolvePanel($server);
            return $manager->getClientUsage($server, $username);
        } catch (RuntimeException $e) {
            $this->logger->error('Usage refresh failed', ['error' => $e->getMessage()]);
            return [];
        }
    }

    public function suspendClient(string $username): bool
    {
        $subscription = $this->subscriptions->findByUsername($username);
        if ($subscription === null) {
            return false;
        }

        $server = $this->servers->findById($subscription->serverId);
        if ($server === null) {
            return false;
        }

        $this->resolvePanel($server)->suspendClient($server, $subscription->username);

        $this->subscriptions->save(
            new \Zorvex\Domain\Entity\Subscription($subscription->toArray() + ['status' => 'suspended'])
        );

        return true;
    }

    public function enableClient(string $username): bool
    {
        $subscription = $this->subscriptions->findByUsername($username);
        if ($subscription === null) {
            return false;
        }

        $server = $this->servers->findById($subscription->serverId);
        if ($server === null) {
            return false;
        }

        $this->resolvePanel($server)->enableClient($server, $subscription->username);

        $this->subscriptions->save(
            new \Zorvex\Domain\Entity\Subscription($subscription->toArray() + ['status' => 'active'])
        );

        return true;
    }

    /**
     * Find a panel manager for the given server type.
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