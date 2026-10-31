<?php

namespace AiphraCore\CalDav\Internal;

use AiphraCore\CalDav\Internal\Xml\{Resource, ResponseParser};

/**
 * PROPFIND читает метаданные адреса: тип ресурса, имя или ссылки на другие адреса.
 * @internal
 */
final readonly class ReadProperties {
    public function __construct(private Connection $connection) {}

    /**
     * includeChildren=false читает только указанный адрес; true — также его дочерние ресурсы.
     * @return list<Resource>
     */
    public function at(string $url, string $propertiesXml, bool $includeChildren = false): array {
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<d:propfind xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">'
            . '<d:prop>' . $propertiesXml . '</d:prop></d:propfind>';
        $response = $this->connection->request('PROPFIND', $url, [
            'Depth' => $includeChildren ? '1' : '0',
            'Content-Type' => 'application/xml; charset=utf-8',
        ], $body);
        return ResponseParser::parse($response);
    }
}
