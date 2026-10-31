<?php

namespace AiphraCore\CalDav\Internal\Discovery;

/**
 * Адрес пользователя и папок с календарями, полученные от сервера.
 * @internal
 */
final readonly class Location {
    /**
     * @param list<string> $calendarFolderUrls
     */
    public function __construct(public array $calendarFolderUrls, public ?string $userUrl) {}
}
