<?php

namespace AiphraCore\Providers\GoogleCalendar;

use AiphraCore\Exceptions\AuthenticationException;
use AiphraCore\Http\{CurlClient, Errors, HttpClient, Response};

/**
 * Авторизованный HTTP-клиент Google. Токен обновляется перед запросом.
 */
final class Client implements HttpClient {
    private ?string $accessToken = null;
    private int $expiresAt = 0;

    public function __construct(private readonly OAuthCredentials $credentials, private readonly HttpClient $http = new CurlClient()) {}

    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response {
        $response = $this->http->request($method, $url, ['Authorization' => 'Bearer ' . $this->token()] + $headers, $body);
        if ($response->status === 401) {
            $this->accessToken = null;
        }
        Errors::check($response);
        return $response;
    }

    private function token(): string {
        if ($this->accessToken !== null && time() < $this->expiresAt) {
            return $this->accessToken;
        }
        $response = $this->http->request('POST', 'https://oauth2.googleapis.com/token', [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ], http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $this->credentials->clientId(),
            'client_secret' => $this->credentials->clientSecret(),
            'refresh_token' => $this->credentials->refreshToken(),
        ], '', '&', PHP_QUERY_RFC3986));
        if ($response->status === 400 || $response->status === 401) {
            throw new AuthenticationException('Google rejected OAuth credentials.', $response->status);
        }
        Errors::check($response);
        $token = $response->json();
        $now = time();
        $expiresIn = $token['expires_in'] ?? null;
        $lifetime = is_int($expiresIn) || is_string($expiresIn)
            ? filter_var($expiresIn, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PHP_INT_MAX - $now]])
            : false;
        if (!is_string($token['access_token'] ?? null) || !$token['access_token'] || !$lifetime) {
            throw new AuthenticationException('Google returned an invalid access token.');
        }
        $this->accessToken = $token['access_token'];
        $this->expiresAt = $now + max(1, $lifetime - 30);
        return $this->accessToken;
    }
}
