<?php

namespace AiphraCore\Providers\GoogleCalendar;

use AiphraCore\Method;
use AiphraCore\Providers\CalDav\Provider as CalDavProvider;
use AiphraCore\Providers\GoogleCalendar\Api\{GetCalendar, GetCalendars};

final class Provider extends CalDavProvider {
    use GetCalendar, GetCalendars;

    protected const CALDAV_URL = 'https://apidata.googleusercontent.com/caldav/v2/';
    protected const API_URL = 'https://www.googleapis.com/calendar/v3/';

    public function __construct(
        private readonly Client $client,
        string $url = self::CALDAV_URL,
    ) {
        parent::__construct($url, $client);
    }

    protected function operations(Method $method): array {
        return match ($method) {
            Method::CalDav => parent::operations($method),
            Method::Api => [
                'getCalendar' => $this->getCalendarViaApi(...),
                'getCalendars' => $this->getCalendarsViaApi(...),
            ],
        };
    }
}
