<?php

namespace AiphraCore\Providers\GoogleCalendar\Api;

use AiphraCore\Objects\Calendars\Calendars;
use AiphraCore\Providers\GoogleCalendar\Internal\Api\ReadCalendarList;

/**
 * Список через Google API с общими фильтрами, offset и limit.
 */
trait GetCalendars {
    protected function getCalendarsViaApi(): Calendars {
        $source = new ReadCalendarList($this->client, self::API_URL . 'users/me/calendarList');
        return $this->collectCalendars($source->read($this->requestPageSize(250)));
    }
}
