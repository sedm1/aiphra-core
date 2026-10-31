<?php

namespace AiphraCore\Http;

use AiphraCore\Exceptions\{AuthenticationException, AuthorizationException, ProviderException,
    ProviderResponseException, RateLimitException, ResourceNotFoundException, TemporaryProviderException, ValidationException};

final class Errors {
    public static function check(Response $response): void {
        $status = $response->status;
        if ($status >= 200 && $status < 300) {
            return;
        }
        $message = 'Provider request failed (HTTP ' . $status . ').';
        throw match (true) {
            $status === 401 => new AuthenticationException($message, $status),
            $status === 429 => new RateLimitException($message, $status),
            $status === 403 => new AuthorizationException($message, $status),
            $status === 404 || $status === 410 => new ResourceNotFoundException($message, $status),
            $status === 400 || $status === 422 => new ValidationException($message, $status),
            $status >= 500 => new TemporaryProviderException($message, $status),
            $status >= 300 && $status < 400 => new ProviderResponseException('Unexpected provider redirect.', $status),
            default => new ProviderException($message, $status),
        };
    }
}
