<?php

namespace AiphraCore\Providers\GoogleCalendar;

use AiphraCore\Exceptions\InvalidConfigurationException;
use SensitiveParameter;

/**
 * OAuth-данные, необходимые для получения access token от Google.
 */
final readonly class OAuthCredentials {
    public function __construct(
        private string $clientId,
        #[SensitiveParameter] private string $clientSecret,
        #[SensitiveParameter] private string $refreshToken
    ) {
        if (!$this->clientId || !$this->clientSecret || !$this->refreshToken) {
            throw new InvalidConfigurationException('Google OAuth credentials must not be empty.');
        }
    }

    public function clientId(): string {
        return $this->clientId;
    }

    public function clientSecret(): string {
        return $this->clientSecret;
    }

    public function refreshToken(): string {
        return $this->refreshToken;
    }
}
