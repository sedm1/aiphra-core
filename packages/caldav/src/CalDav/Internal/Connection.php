<?php

namespace AiphraCore\CalDav\Internal;

use AiphraCore\Http\{HttpClient, Response};

/**
 * HTTP-клиент и начальный адрес сервера, общие для всех операций.
 * @internal
 */
final readonly class Connection {
    private UrlResolver $urls;

    public function __construct(private HttpClient $http, public string $serverUrl) {
        $this->urls = new UrlResolver($serverUrl);
    }

    public function resolveUrl(string $url, ?string $relativeTo = null): string {
        return $this->urls->resolve($url, $relativeTo);
    }

    /**
     * @param array<string, string> $headers
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response {
        return $this->http->request($method, $url, $headers, $body);
    }
}
