<?php

declare(strict_types=1);

namespace Zorvex\Domain\Service;

use Zorvex\Domain\Entity\Server;

/**
 * VPN panel manager interface.
 *
 * Implementations talk to concrete panel REST APIs (Marzban, 3x-ui) behind
 * this uniform contract so the application layer stays panel-agnostic.
 *
 * @package Zorvex\Domain\Service
 */
interface PanelManager
{
    /**
     * Whether this manager understands the given server type.
     */
    public function supports(Server $server): bool;

    /**
     * Test connectivity + credentials.
     */
    public function healthCheck(Server $server): bool;

    /**
     * Create a VPN client on the panel.
     *
     * @param array<string, mixed> $options  e.g. traffic bytes, expiry, proxies.
     * @return array{username: string, config: string, expiryDate?: string} Provision data.
     * @throws \RuntimeException on failure.
     */
    public function createClient(Server $server, string $username, array $options = []): array;

    /**
     * Extend a client's expiry or traffic on the panel.
     *
     * @param array<string, mixed> $modifiers e.g. ['expired_at' => ..., 'data_limit' => ...].
     */
    public function modifyClient(Server $server, string $username, array $modifiers = []): void;

    /**
     * Disable an existing client (suspend).
     */
    public function suspendClient(Server $server, string $username): void;

    /**
     * Re-enable a suspended client.
     */
    public function enableClient(Server $server, string $username): void;

    /**
     * Permanently remove a client and revoke its configs.
     */
    public function deleteClient(Server $server, string $username): void;

    /**
     * Client usage + status from the panel.
     *
     * @return array{usedTraffic: int, trafficLimit: int, expiryDate: ?string, status: string, online: bool}
     */
    public function getClientUsage(Server $server, string $username): array;

    /**
     * Build a subscription/config link for a client.
     */
    public function getSubscriptionUrl(Server $server, string $username): string;
}