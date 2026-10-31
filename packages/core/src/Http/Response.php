<?php

namespace AiphraCore\Http;

use AiphraCore\Exceptions\ProviderResponseException;
use JsonException;

final readonly class Response {
    /**
     * @param array<string, string> $headers Lowercase header names.
     */
    public function __construct(public int $status, public string $body = '', public array $headers = []) {}

    /**
     * Числовые имена полей JSON становятся целочисленными ключами массива PHP.
     * @return array<array-key, mixed>
     */
    public function json(): array {
        try {
            $data = json_decode($this->body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ProviderResponseException('Provider returned invalid JSON.', previous: $exception);
        }
        if (!is_array($data) || !str_starts_with(ltrim($this->body), '{')) {
            throw new ProviderResponseException('Provider response must be a JSON object.');
        }
        return $data;
    }
}
