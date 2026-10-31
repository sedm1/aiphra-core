<?php

namespace AiphraCore\CalDav\Internal\Calendars;

use AiphraCore\CalDav\Internal\{Connection, ReadProperties};
use AiphraCore\Objects\Calendars\Calendar;

/**
 * Читает тип и имя ресурсов сервера и превращает календари в общие DTO.
 * @internal
 */
final readonly class ReadCalendarProperties {
    public function __construct(private Connection $connection) {}

    /**
     * @return iterable<Calendar>
     */
    public function at(string $url, bool $includeChildren = false): iterable {
        $resources = (new ReadProperties($this->connection))->at(
            $url,
            '<d:resourcetype/><d:displayname/>',
            $includeChildren,
        );
        foreach ($resources as $resource) {
            /**
             * Обычные папки тоже попадают в ответ. Календарь отмечен элементом calendar в resourcetype.
             */
            if (!$resource->hasChildElement('DAV:', 'resourcetype', 'urn:ietf:params:xml:ns:caldav', 'calendar')) {
                continue;
            }
            yield new Calendar(
                $this->connection->resolveUrl($resource->url, $url),
                $resource->text('DAV:', 'displayname'),
            );
        }
    }
}
