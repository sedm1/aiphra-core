<?php

namespace AiphraCore\Providers\CalDav;

use AiphraCore\CalDav\Client;
use AiphraCore\Http\{CurlClient, HttpClient};
use AiphraCore\Method;
use AiphraCore\Providers\AbstractProvider;
use AiphraCore\Providers\Traits\{GetCalendar, GetCalendars};

class Provider extends AbstractProvider {
    use GetCalendars, GetCalendar;

    protected readonly Client $caldav;

    public function __construct(string $url, HttpClient $http = new CurlClient()) {
        $this->caldav = new Client($http, $url);
    }

    protected function operations(Method $method): array {
        return $method === Method::CalDav ? [
            'getCalendar' => $this->getCalendar(...),
            'getCalendars' => $this->getCalendars(...),
        ] : [];
    }
}
