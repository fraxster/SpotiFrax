<?php
/**
 * Spotify: OAuth + Web API client for PHP.
 *
 * Tokens are persisted via the Store (tokens.json) so the host stays logged in
 * across requests. Access tokens are refreshed automatically when expired.
 */

declare(strict_types=1);

require_once __DIR__ . '/Store.php';

/** Thrown when Spotify has no active device to accept a queue add. */
final class NoActiveDeviceException extends RuntimeException
{
}

final class Spotify
{
    private const ACCOUNTS = 'https://accounts.spotify.com';
    private const API = 'https://api.spotify.com/v1';

    private array $config;
    private Store $store;

    public function __construct(array $config, Store $store)
    {
        $this->config = $config;
        $this->store = $store;
    }

    // ---------- Auth state ----------

    public function isAuthenticated(): bool
    {
        $t = $this->store->read('tokens');
        return !empty($t['refresh_token'])
            || (!empty($t['access_token']) && ($t['expires_at'] ?? 0) > time());
    }

    public function buildAuthorizeUrl(string $state): string
    {
        $params = http_build_query([
            'response_type' => 'code',
            'client_id'     => $this->config['client_id'],
            'scope'         => $this->config['scopes'],
            'redirect_uri'  => $this->config['redirect_uri'],
            'state'         => $state,
            'show_dialog'   => 'false',
        ]);
        return self::ACCOUNTS . '/authorize?' . $params;
    }

    private function basicAuthHeader(): string
    {
        return 'Basic ' . base64_encode($this->config['client_id'] . ':' . $this->config['client_secret']);
    }

    /** Exchange an authorization code for tokens and persist them. */
    public function exchangeCodeForTokens(string $code): void
    {
        $resp = $this->tokenRequest([
            'grant_type'   => 'authorization_code',
            'code'         => $code,
            'redirect_uri' => $this->config['redirect_uri'],
        ]);
        $this->storeTokenResponse($resp);
        error_log('[SpotiFrax] Token exchange succeeded; host authenticated.');
    }

    private function storeTokenResponse(array $data): void
    {
        $this->store->update('tokens', function (array $t) use ($data): array {
            if (!empty($data['access_token'])) {
                $t['access_token'] = $data['access_token'];
            }
            // Refresh responses may omit refresh_token — keep the existing one.
            if (!empty($data['refresh_token'])) {
                $t['refresh_token'] = $data['refresh_token'];
            }
            $expiresIn = (int) ($data['expires_in'] ?? 3600);
            // Refresh a minute early to avoid edge-of-expiry failures.
            $t['expires_at'] = time() + $expiresIn - 60;
            return $t;
        });
    }

    private function refreshAccessToken(): void
    {
        $t = $this->store->read('tokens');
        if (empty($t['refresh_token'])) {
            throw new RuntimeException('Not authenticated: no refresh token. Host must log in.');
        }
        try {
            $resp = $this->tokenRequest([
                'grant_type'    => 'refresh_token',
                'refresh_token' => $t['refresh_token'],
            ]);
        } catch (SpotifyHttpException $e) {
            // A 400 usually means the refresh token was revoked. Clear it so the
            // display correctly shows the login gate again.
            if ($e->status === 400) {
                $this->store->delete('tokens');
                error_log('[SpotiFrax] Refresh token rejected — cleared. Host must log in again.');
            }
            throw $e;
        }
        $this->storeTokenResponse($resp);
    }

    private function getValidAccessToken(): string
    {
        $t = $this->store->read('tokens');
        if (empty($t['access_token']) || ($t['expires_at'] ?? 0) <= time()) {
            $this->refreshAccessToken();
            $t = $this->store->read('tokens');
        }
        return (string) ($t['access_token'] ?? '');
    }

    // ---------- HTTP helpers ----------

    private function tokenRequest(array $form): array
    {
        [$status, $body] = $this->httpPost(
            self::ACCOUNTS . '/api/token',
            http_build_query($form),
            [
                'Authorization: ' . $this->basicAuthHeader(),
                'Content-Type: application/x-www-form-urlencoded',
            ]
        );
        if ($status < 200 || $status >= 300) {
            throw new SpotifyHttpException("Token request failed", $status, (string) $body);
        }
        $data = json_decode((string) $body, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Authenticated API request. Retries once after a forced refresh on 401.
     * Returns [status, decodedBodyOrNull].
     */
    private function apiRequest(string $method, string $path, array $query = [], bool $retry = true): array
    {
        $token = $this->getValidAccessToken();
        $url = self::API . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }
        [$status, $body] = $this->http($method, $url, null, [
            'Authorization: Bearer ' . $token,
            'Content-Type: application/json',
        ]);

        if ($status === 401 && $retry) {
            $this->refreshAccessToken();
            return $this->apiRequest($method, $path, $query, false);
        }

        $decoded = ($body === '' || $body === null) ? null : json_decode((string) $body, true);
        return [$status, $decoded];
    }

    private function httpPost(string $url, string $body, array $headers): array
    {
        return $this->http('POST', $url, $body, $headers);
    }

    /** Low-level HTTP via cURL. Returns [status:int, body:string]. */
    private function http(string $method, string $url, ?string $body, array $headers): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException('HTTP request failed: ' . $err);
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$status, (string) $resp];
    }

    // ---------- Web API methods ----------

    /** Search tracks; returns trimmed track arrays. */
    public function searchTracks(string $query, int $limit = 12): array
    {
        [$status, $data] = $this->apiRequest('GET', '/search', [
            'q' => $query, 'type' => 'track', 'limit' => $limit,
        ]);
        if ($status < 200 || $status >= 300) {
            throw new SpotifyHttpException('Search failed', $status, json_encode($data));
        }
        $items = $data['tracks']['items'] ?? [];
        return array_map([self::class, 'trimTrack'], $items);
    }

    /** Full details for a single track id. */
    public function getTrack(string $trackId): array
    {
        [$status, $data] = $this->apiRequest('GET', '/tracks/' . rawurlencode($trackId));
        if ($status < 200 || $status >= 300) {
            throw new SpotifyHttpException('Get track failed', $status, json_encode($data));
        }
        return self::trimTrack($data);
    }

    /** Add a track URI to the active device's playback queue. */
    public function addToQueue(string $uri): void
    {
        [$status, $data] = $this->apiRequest('POST', '/me/player/queue', ['uri' => $uri]);
        if ($status === 404) {
            throw new NoActiveDeviceException('No active Spotify device found.');
        }
        if ($status < 200 || $status >= 300) {
            throw new SpotifyHttpException('Add to queue failed', $status, json_encode($data));
        }
    }

    /** Current playback state. Returns ['isPlaying'=>bool,'progressMs'=>int,'track'=>?array]. */
    public function getNowPlaying(): array
    {
        [$status, $data] = $this->apiRequest('GET', '/me/player/currently-playing');
        if ($status === 204 || !$data || empty($data['item'])) {
            return ['isPlaying' => false, 'progressMs' => 0, 'track' => null];
        }
        if ($status < 200 || $status >= 300) {
            throw new SpotifyHttpException('Now playing failed', $status, json_encode($data));
        }
        return [
            'isPlaying'  => (bool) ($data['is_playing'] ?? false),
            'progressMs' => (int) ($data['progress_ms'] ?? 0),
            'track'      => self::trimTrack($data['item']),
        ];
    }

    /** Reduce a Spotify track object to the fields the UI needs. */
    public static function trimTrack(?array $t): ?array
    {
        if (!$t) {
            return null;
        }
        $images = $t['album']['images'] ?? [];
        return [
            'id'         => $t['id'] ?? null,
            'uri'        => $t['uri'] ?? null,
            'name'       => $t['name'] ?? '',
            'durationMs' => (int) ($t['duration_ms'] ?? 0),
            'artists'    => array_map(static fn ($a) => $a['name'] ?? '', $t['artists'] ?? []),
            'album'      => $t['album']['name'] ?? '',
            'image'      => $images[0]['url'] ?? ($images[1]['url'] ?? null),
        ];
    }
}

/** HTTP-level Spotify error carrying the status code and response body. */
final class SpotifyHttpException extends RuntimeException
{
    public int $status;
    public string $body;

    public function __construct(string $message, int $status, ?string $body = null)
    {
        parent::__construct($message . ' (' . $status . ')');
        $this->status = $status;
        $this->body = (string) $body;
    }
}
