<?php

namespace App\Services\Instagram;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Instagram Graph API (Instagram Login) through the egress relay. Never calls Meta directly:
 * the api runs in Iran, where graph.instagram.com is unreachable.
 */
class InstagramClient
{
    public function __construct(private InstagramSettings $settings)
    {
    }

    /** The professional account behind a token: ['user_id' => ..., 'username' => ...]. */
    public function me(?string $token = null): array
    {
        return $this->call('GET', '/me', ['fields' => 'user_id,username,name'], null, $token);
    }

    public function profile(string $igsid): array
    {
        return $this->call('GET', '/' . rawurlencode($igsid), ['fields' => 'name,username']);
    }

    /** One private DM to the author of a comment (Meta allows one per comment, within 7 days). */
    public function privateReply(string $commentId, string $text): array
    {
        return $this->call('POST', '/' . $this->account() . '/messages', [], [
            'recipient' => ['comment_id' => $commentId],
            'message' => ['text' => $text],
        ]);
    }

    public function sendText(string $igsid, string $text): array
    {
        return $this->call('POST', '/' . $this->account() . '/messages', [], [
            'recipient' => ['id' => $igsid],
            'message' => ['text' => $text],
        ]);
    }

    /** A public reply under a comment. */
    public function replyToComment(string $commentId, string $text): array
    {
        return $this->call('POST', '/' . rawurlencode($commentId) . '/replies', [], ['message' => $text]);
    }

    /** ['access_token' => ..., 'expires_in' => seconds]. */
    public function refreshToken(): array
    {
        $token = $this->settings->token() ?? throw new InstagramRejected('Instagram is not connected.');

        return $this->call('GET', '/refresh_access_token', [
            'grant_type' => 'ig_refresh_token', 'access_token' => $token,
        ], null, $token, false);
    }

    private function account(): string
    {
        return rawurlencode($this->settings->accountId() ?? throw new InstagramRejected('Instagram is not connected.'));
    }

    private function call(string $method, string $path, array $query = [], ?array $json = null, ?string $token = null, bool $versioned = true): array
    {
        $relay = config('services.instagram.relay_url');
        $key = config('services.instagram.relay_key');
        if (!$relay || !$key) {
            throw new InstagramUnavailable('Instagram relay is not configured (INSTAGRAM_RELAY_URL / INSTAGRAM_RELAY_KEY).');
        }
        $token ??= $this->settings->token() ?? throw new InstagramRejected('Instagram is not connected.');

        $url = $relay . '/proxy/instagram' . ($versioned ? '/' . config('services.instagram.graph_version') : '') . $path;
        $request = Http::withHeaders(['X-Relay-Key' => $key])
            ->withToken($token)
            ->acceptJson()
            ->timeout(15)
            ->connectTimeout(5);

        try {
            $response = $method === 'GET'
                ? $request->get($url, $query)
                : $request->post($url . ($query ? '?' . http_build_query($query) : ''), $json ?? []);
        } catch (ConnectionException $e) {
            throw new InstagramUnavailable('Relay unreachable: ' . $e->getMessage());
        }

        return $this->result($response);
    }

    private function result(Response $response): array
    {
        if ($response->successful()) {
            return $response->json() ?? [];
        }
        $error = $response->json('error');
        // Meta errors look like {"error":{"message":..,"code":..}}; anything else came from the relay.
        if (is_array($error) && isset($error['message']) && $response->status() < 500 && $response->status() !== 429) {
            throw new InstagramRejected(
                (string) $error['message'],
                isset($error['code']) ? (int) $error['code'] : null,
                isset($error['error_subcode']) ? (int) $error['error_subcode'] : null,
            );
        }
        $detail = is_string($error) ? $error : mb_substr($response->body(), 0, 200);

        throw new InstagramUnavailable("Instagram request failed ({$response->status()}): {$detail}");
    }
}
