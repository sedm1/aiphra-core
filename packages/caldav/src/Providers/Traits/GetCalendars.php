<?php

namespace AiphraCore\Providers\Traits;

use AiphraCore\Objects\Calendars\Calendars;

/**
 * Получение списка календарей с проверкой фильтров Aiphra.
 */
trait GetCalendars {
    protected function getCalendars(): Calendars {
        return $this->collectCalendars($this->caldav->calendars());
    }
}
