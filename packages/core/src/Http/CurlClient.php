<?php

namespace AiphraCore\Http;

use AiphraCore\Exceptions\{InvalidConfigurationException, ProviderResponseException, TemporaryProviderException};

final readonly class CurlClient implements HttpClient {
    public function __construct(private int $timeout = 30, private int $maxResponseBytes = 20971520) {
        if (!extension_loaded('curl')) {
            throw new InvalidConfigurationException('CurlClient requires ext-curl. Install it or supply your own HttpClient.');
        }
        if ($timeout < 1 || $maxResponseBytes < 1) {
            throw new InvalidConfigurationException('HTTP timeout and response size limit must be positive.');
        }
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response {
        if (!$method) {
            throw new InvalidConfigurationException('HTTP method must not be empty.');
        }
        $handle = curl_init($url);
        if ($handle === false) {
            throw new TemporaryProviderException('Could not initialize cURL.');
        }
        $responseBody = '';
        $responseHeaders = [];
        $tooLarge = false;
        $options = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => array_map(static fn ($name, $value) => $name . ': ' . $value, array_keys($headers), $headers),
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS | CURLPROTO_HTTP,
            CURLOPT_WRITEFUNCTION => function ($handle, string $chunk) use (&$responseBody, &$tooLarge): int {
                if (strlen($responseBody) + strlen($chunk) > $this->maxResponseBytes) {
                    $tooLarge = true;
                    return 0;
                }
                $responseBody .= $chunk;
                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseHeaders): int {
                if (str_starts_with($line, 'HTTP/')) {
                    $responseHeaders = [];
                } elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $responseHeaders[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($handle, $options);
        if (curl_exec($handle) === false) {
            if ($tooLarge) {
                throw new ProviderResponseException('Response exceeded maxResponseBytes.');
            }
            throw new TemporaryProviderException('HTTP request failed: ' . curl_error($handle), curl_errno($handle));
        }
        return new Response(curl_getinfo($handle, CURLINFO_RESPONSE_CODE), $responseBody, $responseHeaders);
    }
}
