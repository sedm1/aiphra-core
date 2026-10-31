<?php

namespace AiphraCore\CalDav\Internal\Discovery;

use AiphraCore\CalDav\Internal\{Connection, ReadProperties};
use AiphraCore\CalDav\Internal\Xml\Resource;
use AiphraCore\Exceptions\ProviderResponseException;

/**
 * Находит папки пользователя, в которых сервер хранит календари.
 * @internal
 */
final readonly class FindCalendarFolders {
    public function __construct(private Connection $connection) {}

    /**
     * @return list<string>
     */
    public function find(): array {
        $serverUrl = $this->connection->serverUrl;
        $location = $this->readLocation($serverUrl);

        /**
         * Если начальный адрес не сообщил папки, спрашиваем их по адресу текущего пользователя.
         */
        if ($location->calendarFolderUrls === [] && $location->userUrl !== null && $location->userUrl !== $serverUrl) {
            $location = $this->readLocation($location->userUrl);
        }
        if ($location->calendarFolderUrls === []) {
            throw new ProviderResponseException('CalDAV discovery did not return calendar-home-set.');
        }
        return array_values(array_unique($location->calendarFolderUrls));
    }

    private function readLocation(string $url): Location {
        $resources = (new ReadProperties($this->connection))->at($url, '<d:current-user-principal/><c:calendar-home-set/>');
        return new Location(
            calendarFolderUrls: $this->readFolderUrls($resources, $url),
            userUrl: $this->readUserUrl($resources, $url),
        );
    }

    /**
     * calendar-home-set — список ссылок на папки с календарями.
     * @param list<Resource> $resources
     * @return list<string>
     */
    private function readFolderUrls(array $resources, string $relativeTo): array {
        $folderUrls = [];
        foreach ($resources as $resource) {
            foreach ($resource->links('urn:ietf:params:xml:ns:caldav', 'calendar-home-set') as $folderUrl) {
                $folderUrls[] = $this->connection->resolveUrl($folderUrl, $relativeTo);
            }
        }
        return $folderUrls;
    }

    /**
     * current-user-principal — ссылка на ресурс текущего авторизованного пользователя.
     * @param list<Resource> $resources
     */
    private function readUserUrl(array $resources, string $relativeTo): ?string {
        $userUrl = null;
        foreach ($resources as $resource) {
            $link = $resource->links('DAV:', 'current-user-principal')[0] ?? null;
            if ($link !== null) {
                $userUrl = $this->connection->resolveUrl($link, $relativeTo);
            }
        }
        return $userUrl;
    }
}
