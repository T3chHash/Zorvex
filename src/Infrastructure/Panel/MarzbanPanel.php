<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Panel;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Zorvex\Domain\Entity\Server;
use Zorvex\Domain\Service\PanelManager;

/**
 * Marzban panel REST API client.
 *
 * Implements the Marzban v1 API surface used by this application:
 *   POST /api/user, PUT /api/user/{username}, PATCH /api/user/{username},
 *   DELETE /api/user/{username}, GET /api/user/{username}
 *
 * @package Zorvex\Infrastructure\Panel
 */
final class MarzbanPanel implements PanelManager
{
    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function supports(Server $server): bool
    {
        return $server->isMarzban();
    }

    public function healthCheck(Server $server): bool
    {
        try {
            $response = $this->request($server, 'GET', '/api/system');
            return isset($response['version']) || $response !== [];
        } catch (RuntimeException) {
            return false;
        }
    }

    public function createClient(Server $server, string $username, array $options = []): array
    {
        $traffic = (int) ($options['traffic'] ?? 0);
        $expiry = $options['expiry'] ?? null;
        $proxies = $options['proxies'] ?? [
            'vless' => [
                'flow' => 'xtls-rprx-vision',
                'settings' => ['vless' => ['id' => $this->uuid(), 'flow' => 'xtls-rprx-vision']],
            ],
        ];

        $payload = [
            'username' => $username,
            'proxies' => $proxies,
            'inbounds' => ['vless', 'vless_reality_settings', 'vmess', 'vless_grpc_settings', 'vless_ws_settings'],
            'data_limit' => $traffic > 0 ? $traffic : null,
            'data_limit_reset_strategy' => 'no_reset',
            'expire' => $expiry !== null ? (int) $expiry : null,
            'status' => 'active',
        ];

        // Remove nulls so the API isn't confused.
        $payload = array_filter($payload, static fn (mixed $v): bool => $v !== null);

        $this->request($server, 'POST', '/api/user', $payload);

        $subscription = $this->getSubscriptionUrl($server, $username);

        return [
            'username' => $username,
            'config' => $subscription,
            'expiryDate' => $expiry !== null ? gmdate('Y-m-d H:i:s', (int) $expiry) : null,
        ];
    }

    public function modifyClient(Server $server, string $username, array $modifiers = []): void
    {
        $payload = [];

        if (array_key_exists('data_limit', $modifiers)) {
            $payload['data_limit'] = (int) $modifiers['data_limit'];
        }

        if (array_key_exists('expire', $modifiers) || array_key_exists('expired_at', $modifiers)) {
            $payload['expire'] = (int) ($modifiers['expire'] ?? $modifiers['expired_at'] ?? 0);
        }

        if ($payload !== []) {
            $this->request($server, 'PUT', '/api/user/' . rawurlencode($username), $payload);
        }
    }

    public function suspendClient(Server $server, string $username): void
    {
        $this->request($server, 'PATCH', '/api/user/' . rawurlencode($username), ['status' => 'disabled']);
    }

    public function enableClient(Server $server, string $username): void
    {
        $this->request($server, 'PATCH', '/api/user/' . rawurlencode($username), ['status' => 'active']);
    }

    public function deleteClient(Server $server, string $username): void
    {
        try {
            $this->request($server, 'DELETE', '/api/user/' . rawurlencode($username));
        } catch (RuntimeException $e) {
            // Deleting a non-existent client should not be fatal when the
            // panel is already consistent.
            if ($e->getCode() !== 404) {
                throw $e;
            }
        }
    }

    public function getClientUsage(Server $server, string $username): array
    {
        $data = $this->request($server, 'GET', '/api/user/' . rawurlencode($username));

        $used = (int) ($data['used_traffic'] ?? 0);
        $limit = (int) ($data['data_limit'] ?? 0);
        $expires = isset($data['expire']) && (int) $data['expire'] > 0
            ? gmdate('Y-m-d H:i:s', (int) $data['expire'])
            : null;
        $status = (string) ($data['status'] ?? 'active');
        $online = isset($data['online_at']) && $data['online_at'] !== null;

        return [
            'usedTraffic' => $used,
            'trafficLimit' => $limit,
            'expiryDate' => $expires,
            'status' => $status === 'disabled' ? 'suspended' : $status,
            'online' => $online,
        ];
    }

    public function getSubscriptionUrl(Server $server, string $username): string
    {
        return $server->url . '/sub/' . rawurlencode($username);
    }

    /**
     * Perform an authenticated JSON HTTP request to the Marzban API.
     *
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function request(Server $server, string $method, string $endpoint, ?array $payload = null): array
    {
        $url = $server->url . $endpoint;
        $timeout = (int) config('panels.timeout', 15);
        $retries = max(1, (int) config('panels.retries', 3));

        $ch = curl_init($url);

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $server->apiKey,
            'Content-Type: application/json',
        ];

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
        ]);

        if ($method !== 'GET') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            if ($payload !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_SLASHES));
            }
        }

        $lastError = '';
        $body = false;
        $status = 0;

        for ($attempt = 1; $attempt <= $retries; $attempt++) {
            $body = curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $lastError = curl_error($ch);

            if ($body !== false && $status < 500 && $status !== 429) {
                break;
            }

            if ($attempt < $retries) {
                usleep(250_000 * $attempt);
            }
        }

        curl_close($ch);

        $decoded = $body !== false ? json_decode((string) $body, true) : null;

        // Marzban returns 200 with a JSON body on success, 4xx with {"detail": ...}.
        if ($status >= 400 || $decoded === null) {
            $detail = is_array($decoded) ? ($decoded['detail'] ?? json_encode($decoded)) : $lastError;
            $this->logger->error('Marzban request failed', [
                'method' => $method,
                'url' => $url,
                'status' => $status,
                'detail' => $detail,
            ]);

            throw new RuntimeException(
                sprintf('Marzban %s %s failed with HTTP %d: %s', $method, $endpoint, $status, (string) $detail),
                $status
            );
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * RFC 4122 v4 UUID.
     */
    private function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        $hex = bin2hex($bytes);

        return sprintf(
            '%s-%s-%s-%s-%s',
            substr($hex, 0, 8),
            substr($hex, 8, 4),
            substr($hex, 12, 4),
            substr($hex, 16, 4),
            substr($hex, 20, 12)
        );
    }
}