<?php

namespace AiphraCore\CalDav;

use AiphraCore\CalDav\Internal\Calendars\{GetCalendar, GetCalendars};
use AiphraCore\CalDav\Internal\Connection;
use AiphraCore\Http\HttpClient;

/**
 * Точка входа в CalDAV: каждая операция реализована отдельным trait.
 */
final readonly class Client {
    use GetCalendar, GetCalendars;

    private Connection $connection;

    public function __construct(HttpClient $http, string $url) {
        $this->connection = new Connection($http, $url);
    }
}
