<?php

namespace AiphraCore\CalDav\Internal;

use AiphraCore\Exceptions\{InvalidConfigurationException, ProviderResponseException};

/**
 * Превращает относительные ссылки в полные URL на том же сервере: протокол, хост и порт.
 * @internal
 */
final readonly class UrlResolver {
    private string $origin;

    public function __construct(private string $endpoint) {
        if (!self::isHttpUrl($endpoint)) {
            throw new InvalidConfigurationException('A CalDAV endpoint must be an absolute HTTP(S) URL without credentials or fragments.');
        }
        $this->origin = self::origin($endpoint);
    }

    public function resolve(string $url, ?string $relativeTo = null): string {
        $absoluteUrl = self::makeAbsolute($url, $relativeTo ?? $this->endpoint);
        $this->requireSameServer($absoluteUrl);
        return $this->normalizePath($absoluteUrl);
    }

    private static function makeAbsolute(string $url, string $baseUrl): string {
        if (str_starts_with($url, '//')) {
            return parse_url($baseUrl, PHP_URL_SCHEME) . ':' . $url;
        }
        if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
            return $url;
        }
        $basePath = parse_url($baseUrl, PHP_URL_PATH) ?: '/';
        $baseDirectory = substr($basePath, 0, strrpos($basePath, '/') + 1);
        $path = str_starts_with($url, '/') ? $url : $baseDirectory . $url;
        return self::origin($baseUrl) . $path;
    }

    private function requireSameServer(string $url): void {
        if (!self::isHttpUrl($url) || self::origin($url) !== $this->origin) {
            throw new ProviderResponseException('CalDAV href must remain on the configured HTTP(S) origin.');
        }
    }

    private function normalizePath(string $url): string {
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            switch ($segment) {
                case '..':
                    array_pop($segments);
                    break;
                case '':
                case '.':
                    break;
                default:
                    $segments[] = $segment;
            }
        }
        $normalizedUrl = $this->origin . '/' . implode('/', $segments);
        if (str_ends_with($path, '/') && $segments !== []) {
            $normalizedUrl .= '/';
        }
        $query = parse_url($url, PHP_URL_QUERY);
        return $normalizedUrl . ($query === null ? '' : '?' . $query);
    }

    private static function isHttpUrl(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) !== false
            && in_array(parse_url($url, PHP_URL_SCHEME), ['https', 'http'], true)
            && parse_url($url, PHP_URL_USER) === null
            && parse_url($url, PHP_URL_FRAGMENT) === null;
    }

    private static function origin(string $url): string {
        $scheme = strtolower(parse_url($url, PHP_URL_SCHEME) ?: '');
        $host = strtolower(parse_url($url, PHP_URL_HOST) ?: '');
        $port = parse_url($url, PHP_URL_PORT) ?? ($scheme === 'https' ? 443 : 80);
        $defaultPort = $scheme === 'https' ? 443 : 80;
        return $scheme . '://' . $host . ($port === $defaultPort ? '' : ':' . $port);
    }
}
