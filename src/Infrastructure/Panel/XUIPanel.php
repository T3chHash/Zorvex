<?php

declare(strict_types=1);

namespace Zorvex\Infrastructure\Panel;

use Psr\Log\LoggerInterface;
use RuntimeException;
use Zorvex\Domain\Entity\Server;
use Zorvex\Domain\Service\PanelManager;

/**
 * 3x-ui (X-UI fork) panel REST API client.
 *
 * Implements the endpoints this application needs:
 *   POST /login, GET /panel/api/inbounds/list, GET /panel/api/inbounds/get/{inboundId},
 *   GET /panel/api/inbounds/{action}/{inboundId}, POST /panel/api/inbounds/addClient
 *
 * @package Zorvex\Infrastructure\Panel
 */
final class XUIPanel implements PanelManager
{
    private const SETTINGS =
        '{"clients":[],"decryption":"none","fallbacks":[{"dest":80,"xver":0}],"streamSettings":{' .
        '"network":"ws","wsSettings":{"path":"/ws","headers":{"Host":""}},"security":"reality",' .
        '"realitySettings":{"settings":{"publicKey":"","fingerprint":"chrome","serverNames":[""],' .
        '"privateKey":"","shortIds":[""]}}}}';

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function supports(Server $server): bool
    {
        return $server->isXui();
    }

    public function healthCheck(Server $server): bool
    {
        try {
            $this->listInbounds($server);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }

    public function createClient(Server $server, string $username, array $options = []): array
    {
        $inboundId = (int) ($options['inbound_id'] ?? 1);
        $traffic = (int) ($options['traffic'] ?? 0); // bytes
        $expiry = $options['expiry'] ?? null;
        $uuid = $this->uuid();

        $client = [
            'id' => $uuid,
            'flow' => 'xtls-rprx-vision',
            'email' => $username,
            'limitIp' => 0,
            'totalGB' => 0, // traffic enforced via expiry/interval; GB limit optional
            'expiryTime' => $expiry !== null ? ((int) $expiry) * 1000 : 0,
            'enable' => true,
            'tgId' => '',
            'subId' => substr(md5($username), 0, 12),
            'reset' => 0,
        ];

        // Apply a data-limit in GB when requested (approximate, panel-native).
        if ($traffic > 0) {
            $client['totalGB'] = (int) ceil($traffic / (1024 ** 3));
        }

        $payload = ['id' => $inboundId, 'settings' => json_encode(['clients' => [$client]])];

        $this->requestJson($server, 'POST', '/panel/api/inbounds/addClient', $payload);

        $config = $this->buildClientConfig($server, $inboundId, $uuid, $username);

        return [
            'username' => $username,
            'config' => $config,
            'expiryDate' => $expiry !== null ? gmdate('Y-m-d H:i:s', (int) $expiry) : null,
        ];
    }

    public function modifyClient(Server $server, string $username, array $modifiers = []): void
    {
        // 3x-ui addClient endpoint upserts: call it again with the full client def.
        // To keep it safe we only support resolution via existing settings state —
        // find the client, patch expiry/totalGB, then upsert.
        $inboundId = (int) ($modifiers['inbound_id'] ?? 1);

        $clients = $this->clientsForInbound($server, $inboundId);
        $current = null;
        foreach ($clients as $client) {
            if (($client['email'] ?? '') === $username) {
                $current = $client;
                break;
            }
        }

        if ($current === null) {
            throw new RuntimeException("3x-ui client [{$username}] not found on inbound [{$inboundId}].");
        }

        if (array_key_exists('data_limit', $modifiers)) {
            $current['totalGB'] = (int) ceil((int) $modifiers['data_limit'] / (1024 ** 3));
        }
        if (array_key_exists('expire', $modifiers) || array_key_exists('expired_at', $modifiers)) {
            $exp = (int) ($modifiers['expire'] ?? $modifiers['expired_at'] ?? 0);
            $current['expiryTime'] = $exp > 0 ? $exp * 1000 : 0;
        }

        $this->requestJson(
            $server,
            'POST',
            '/panel/api/inbounds/addClient',
            ['id' => $inboundId, 'settings' => json_encode(['clients' => [$current]])]
        );
    }

    public function suspendClient(Server $server, string $username): void
    {
        $this->modifyClientInAnyInbound($server, $username, ['enable' => false]);
    }

    public function enableClient(Server $server, string $username): void
    {
        $this->modifyClientInAnyInbound($server, $username, ['enable' => true]);
    }

    /**
     * Find the client across all inbounds and apply a patch.
     */
    private function modifyClientInAnyInbound(Server $server, string $username, array $patch): void
    {
        foreach ($this->requestJson($server, 'GET', '/panel/api/inbounds/list') as $inbound) {
            $id = (int) ($inbound['id'] ?? 0);
            foreach (($inbound['clientStats'] ?? []) as $client) {
                if (($client['email'] ?? '') === $username) {
                    $client = array_merge($client, $patch);
                    $this->requestJson(
                        $server,
                        'POST',
                        '/panel/api/inbounds/addClient',
                        ['id' => $id, 'settings' => json_encode(['clients' => [$client]])]
                    );
                    return;
                }
            }
        }

        throw new RuntimeException("3x-ui client [{$username}] not found on any inbound.");
    }

    public function deleteClient(Server $server, string $username): void
    {
        $inboundId = 1;
        $removed = false;

        foreach ($this->requestJson($server, 'GET', '/panel/api/inbounds/list') as $inbound) {
            $id = (int) ($inbound['id'] ?? 0);
            foreach (($inbound['clientStats'] ?? []) as $client) {
                if (($client['email'] ?? '') === $username) {
                    $this->requestJson(
                        $server,
                        'POST',
                        '/panel/api/inbounds/delClient/' . $id,
                        ['id' => $id, 'clientId' => ($client['id'] ?? $username)]
                    );
                    $removed = true;
                    break 2;
                }
            }
        }

        if (!$removed) {
            $this->logger->warning('3x-ui deleteClient: user not found', ['server' => $server->name, 'username' => $username]);
        }
    }

    public function getClientUsage(Server $server, string $username): array
    {
        foreach ($this->requestJson($server, 'GET', '/panel/api/inbounds/list') as $inbound) {
            foreach (($inbound['clientStats'] ?? []) as $client) {
                if (($client['email'] ?? '') === $username) {
                    $used = (int) ($client['up'] ?? 0) + (int) ($client['down'] ?? 0);
                    $limitBytes = (int) ($client['totalGB'] ?? 0) * (1024 ** 3);
                    $expiryMs = (int) ($client['expiryTime'] ?? 0);

                    return [
                        'usedTraffic' => $used,
                        'trafficLimit' => $limitBytes,
                        'expiryDate' => $expiryMs > 0 ? gmdate('Y-m-d H:i:s', (int) floor($expiryMs / 1000)) : null,
                        'status' => isset($client['enable']) && !$client['enable'] ? 'suspended' : 'active',
                        'online' => (bool) ($client['isOnline'] ?? false),
                    ];
                }
            }
        }

        throw new RuntimeException("3x-ui client [{$username}] usage not found.");
    }

    public function getSubscriptionUrl(Server $server, string $username): string
    {
        // 3x-ui panels with the subscription feature enabled serve `/sub/{subId}`
        // where subId is the per-client subscription slug we assign on creation.
        return $server->url . '/sub/' . substr(md5($username), 0, 12);
    }

    /**
     * List all inbounds.
     *
     * @return list<array<string, mixed>>
     */
    private function listInbounds(Server $server): array
    {
        $response = $this->requestJson($server, 'GET', '/panel/api/inbounds/list');
        $obj = $response['obj'] ?? [];

        return is_array($obj) ? $obj : [];
    }

    /**
     * All clients registered on a given inbound.
     *
     * @return list<array<string, mixed>>
     */
    private function clientsForInbound(Server $server, int $inboundId): array
    {
        $inbounds = $this->listInbounds($server);
        foreach ($inbounds as $inbound) {
            if ((int) ($inbound['id'] ?? 0) === $inboundId) {
                $clients = $inbound['clientStats'] ?? [];
                return is_array($clients) ? $clients : [];
            }
        }

        return [];
    }

    /**
     * Build a vless:// share link approximating the reality settings.
     */
    private function buildClientConfig(Server $server, int $inboundId, string $uuid, string $username): string
    {
        $host = parse_url($server->url, PHP_URL_HOST) ?: 'example.com';
        $port = parse_url($server->url, PHP_URL_PORT) ?: '443';

        $params = http_build_query([
            'type' => 'ws',
            'security' => 'reality',
            'pbk' => '',
            'fp' => 'chrome',
            'sni' => $host,
            'sid' => '',
            'spx' => '/',
            'path' => '/ws',
            'encryption' => 'none',
            'headerType' => 'none',
        ]);

        return sprintf('vless://%s@%s:%s?%s#%s', $uuid, $host, $port, $params, rawurlencode($username));
    }

    /**
     * Perform an authenticated JSON request against the 3x-ui panel.
     *
     * Authenticates once and reuses the session cookie from the login response.
     *
     * @param array<string, mixed>|null $payload
     * @return array<string, mixed>
     */
    private function requestJson(Server $server, string $method, string $endpoint, ?array $payload = null): array
    {
        $timeout = (int) config('panels.timeout', 15);

        // 1. Authenticate (3x-ui takes form-encoded credentials and returns
        //    a session cookie; API key doubles as the password).
        [$username, $password] = $this->credentialsFrom($server);
        $login = curl_init($server->url . '/login');
        curl_setopt_array($login, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query(['username' => $username, 'password' => $password]),
            CURLOPT_HEADER => true, // capture Set-Cookie headers in the response.
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        ]);
        $loginResponse = curl_exec($login);
        curl_close($login);

        $this->loginCookie = $this->extractCookies((string) $loginResponse);

        // 2. Perform the actual request.
        $url = $server->url . $endpoint;
        $ch = curl_init($url);

        $headers = ['Accept: application/json'];
        if ($this->loginCookie !== '') {
            $headers[] = 'Cookie: ' . $this->loginCookie;
        }
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
        }

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

        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        $decoded = $body !== false ? json_decode((string) $body, true) : null;

        if ($status >= 400 || $decoded === null) {
            $detail = is_array($decoded) ? json_encode($decoded) : $error;
            $this->logger->error('3x-ui request failed', ['method' => $method, 'url' => $url, 'status' => $status, 'detail' => $detail]);
            throw new RuntimeException(sprintf('3x-ui %s %s failed (HTTP %d): %s', $method, $endpoint, $status, (string) $detail), $status);
        }

        return is_array($decoded) ? $decoded : [];
    }

    private string $loginCookie = '';

    /**
     * Pull session cookies out of a login Header+cst response.
     */
    private function extractCookies(string $raw): string
    {
        $cookies = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (stripos($line, 'Set-Cookie:') === 0) {
                $pair = explode(';', substr($line, 11), 2)[0];
                $cookies[] = trim($pair);
            }
        }

        return implode('; ', $cookies);
    }

    /**
     * Derive login credentials from the server configuration.
     *
     * The 3x-ui API key column stores `username:password` or `password` alone.
     *
     * @return array{0: string, 1: string}
     */
    private function credentialsFrom(Server $server): array
    {
        $raw = $server->apiKey;

        if (str_contains($raw, ':')) {
            [$user, $pass] = explode(':', $raw, 2);
            return [$user, $pass];
        }

        $user = parse_url($server->url, PHP_URL_USER) ?: 'admin';

        return [$user, $raw];
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

        return sprintf('%s-%s-%s-%s-%s', substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4), substr($hex, 16, 4), substr($hex, 20, 12));
    }
}