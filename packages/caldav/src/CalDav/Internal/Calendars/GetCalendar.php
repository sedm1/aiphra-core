<?php

namespace AiphraCore\CalDav\Internal\Calendars;

use AiphraCore\Exceptions\ResourceNotFoundException;
use AiphraCore\Objects\Calendars\Calendar;

/**
 * Календарь по известному URL читается напрямую, без поиска папок пользователя.
 * @internal
 */
trait GetCalendar {
    public function calendar(string $id): Calendar {
        $calendarUrl = $this->connection->resolveUrl($id);
        $readCalendars = new ReadCalendarProperties($this->connection);

        foreach ($readCalendars->at($calendarUrl) as $calendar) {
            if ($calendar->getId() === $calendarUrl) {
                return $calendar;
            }
        }
        throw new ResourceNotFoundException('Calendar not found.');
    }
}
