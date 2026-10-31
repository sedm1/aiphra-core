<?php

namespace AiphraCore\Providers\Traits;

use AiphraCore\Exceptions\{ResourceNotFoundException, ValidationException};
use AiphraCore\Objects\Calendars\Calendar;

/**
 * Получение одного календаря с проверкой фильтров Aiphra.
 */
trait GetCalendar {
    protected function getCalendar(): Calendar {
        foreach ($this->filters as $filter) {
            if ($filter->name !== 'id') {
                continue;
            }
            $calendar = $this->caldav->calendar($filter->values[0]);
            if (!$this->matches($calendar)) {
                throw new ResourceNotFoundException('Calendar does not match the filters.');
            }
            return $calendar;
        }
        throw new ValidationException('A single calendar requires an id Equals filter.');
    }
}
