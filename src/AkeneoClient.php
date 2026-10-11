<?php

namespace Antonny\AkeneoMcp;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

class AkeneoClient
{
    /**
     * Get a cached access token, requesting a new one when none is cached.
     *
     * @throws RequestException|ConnectionException
     */
    public function getAccessToken(): string
    {
        $token = Cache::get($this->tokenCacheKey());

        if (is_string($token) && $token !== '') {
            return $token;
        }

        return $this->refreshAccessToken();
    }

    /**
     * Request a new access token and cache it slightly shorter than its lifetime.
     *
     * @throws RequestException|ConnectionException
     */
    public function refreshAccessToken(): string
    {
        $response = Http::withBasicAuth(
            (string) config('akeneo-mcp.client_id'),
            (string) config('akeneo-mcp.client_secret')
        )
            ->timeout(30)
            ->retry(3, 500, fn (Throwable $exception): bool => $exception instanceof ConnectionException)
            ->post($this->url('/api/oauth/v1/token'), [
                'grant_type' => 'password',
                'username'   => config('akeneo-mcp.username'),
                'password'   => config('akeneo-mcp.password'),
            ])
            ->throw();

        $token = $response->json('access_token');

        if (! is_string($token) || $token === '') {
            throw new RuntimeException('Akeneo did not return an access token.');
        }

        $expiresIn = (int) $response->json('expires_in', 3600);

        Cache::put($this->tokenCacheKey(), $token, max($expiresIn - 60, 60));

        return $token;
    }

    /**
     * Build an authenticated request that retries connection failures and refreshes an expired token once.
     *
     * @throws RequestException|ConnectionException
     */
    public function request(): PendingRequest
    {
        $hasRefreshedToken = false;

        return Http::withToken($this->getAccessToken())
            ->acceptJson()
            ->timeout(30)
            ->retry(3, 500, function (Throwable $exception, PendingRequest $request) use (&$hasRefreshedToken): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                if ($exception instanceof RequestException && $exception->response->status() === 401 && ! $hasRefreshedToken) {
                    $hasRefreshedToken = true;
                    Cache::forget($this->tokenCacheKey());
                    $request->withToken($this->refreshAccessToken());

                    return true;
                }

                return false;
            });
    }

    public function url(string $path): string
    {
        return rtrim((string) config('akeneo-mcp.base_url'), '/') . $path;
    }

    private function tokenCacheKey(): string
    {
        return 'akeneo_mcp_access_token:' . md5((string) config('akeneo-mcp.base_url'));
    }
}
