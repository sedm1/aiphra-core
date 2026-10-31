<?php

namespace AiphraCore\CalDav\Internal\Calendars;

use AiphraCore\CalDav\Internal\Discovery\FindCalendarFolders;
use AiphraCore\Objects\Calendars\Calendar;

/**
 * Список календарей: найти папки пользователя и прочитать календари в каждой папке.
 * @internal
 */
trait GetCalendars {
    /**
     * @return iterable<Calendar>
     */
    public function calendars(): iterable {
        $folderUrls = (new FindCalendarFolders($this->connection))->find();
        $readCalendars = new ReadCalendarProperties($this->connection);
        $returnedIds = [];

        foreach ($folderUrls as $folderUrl) {
            foreach ($readCalendars->at($folderUrl, includeChildren: true) as $calendar) {
                /**
                 * Один общий календарь может встретиться в нескольких папках.
                 */
                if (isset($returnedIds[$calendar->getId()])) {
                    continue;
                }
                $returnedIds[$calendar->getId()] = true;
                yield $calendar;
            }
        }
    }
}
