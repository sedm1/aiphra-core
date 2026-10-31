<?php

declare(strict_types=1);

use AiphraCore\CalDav\Client as CalDavClient;
use AiphraCore\Exceptions\{AuthorizationException, InvalidConfigurationException, ProviderResponseException, ResourceNotFoundException, UnsupportedOperationException, ValidationException};
use AiphraCore\Filters\{Field, Operator};
use AiphraCore\Http\{HttpClient, Response};
use AiphraCore\Operation;
use AiphraCore\Providers\AbstractProvider;
use AiphraCore\Providers\CalDav\Provider;
use AiphraCore\Providers\GoogleCalendar;
use AiphraCore\Providers\Traits\GetCalendars;

set_error_handler(static function (int $severity, string $message, string $file, int $line): never {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

function same(mixed $expected, mixed $actual): void {
    if ($expected !== $actual) {
        throw new RuntimeException('Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}

function raises(string $class, callable $callback): void {
    try { $callback(); } catch (Throwable $exception) {
        if (!$exception instanceof $class) { throw $exception; }
        return;
    }
    throw new RuntimeException('Expected exception: ' . $class);
}

final class FakeHttp implements HttpClient {
    public array $steps = [];
    public array $requests = [];

    public function expect(string $method, string $url, Response|Closure $response, ?string $depth = null): self {
        $this->steps[] = [$method, $url, $response, $depth];
        return $this;
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null): Response {
        $this->requests[] = [$method, $url, $headers, $body];
        $step = array_shift($this->steps);
        if ($step === null) { throw new RuntimeException('Unexpected HTTP: ' . $method . ' ' . $url); }
        same($step[0], $method);
        same($step[1], $url);
        if ($step[3] !== null) { same($step[3], $headers['Depth'] ?? null); }
        if ($method === 'PROPFIND') {
            $xml = new DOMDocument();
            same(true, $xml->loadXML($body));
            same('propfind', $xml->documentElement->localName);
            same('DAV:', $xml->documentElement->namespaceURI);
        }
        return $step[2] instanceof Closure ? $step[2]($headers, $body) : $step[2];
    }

    public function done(): void { same([], $this->steps); }
}

function eq(string $field, string $value): Field { return Field::genFilter($field, Operator::Equals, [$value]); }
function xmlText(string $value): string { return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8'); }

function entry(string $href, string $properties, string $extra = ''): string {
    return '<d:response><d:href>' . xmlText($href) . '</d:href><d:propstat><d:prop>' . $properties
        . '</d:prop><d:status>HTTP/1.1 200 OK</d:status></d:propstat>' . $extra . '</d:response>';
}

function multi(string $entries): Response {
    return new Response(207, '<d:multistatus xmlns:d="DAV:" xmlns:c="urn:ietf:params:xml:ns:caldav">' . $entries . '</d:multistatus>');
}

function calendarsXml(): Response {
    return multi(entry('/home/', '<d:resourcetype><d:collection/></d:resourcetype>')
        . entry('/home/work/', '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Работа &amp; встречи</d:displayname>')
        . entry('/home/personal/', '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Личное</d:displayname>'));
}

function discovery(FakeHttp $http): void {
    $http->expect('PROPFIND', 'https://dav.test/', multi(entry('/', '<d:current-user-principal><d:href>/users/me/</d:href></d:current-user-principal>',
        '<d:propstat><d:prop><c:calendar-home-set/></d:prop><d:status>HTTP/1.1 404 Not Found</d:status></d:propstat>')), '0')
        ->expect('PROPFIND', 'https://dav.test/users/me/', multi(entry('/users/me/', '<c:calendar-home-set><d:href>/home/</d:href></c:calendar-home-set>')), '0')
        ->expect('PROPFIND', 'https://dav.test/home/', calendarsXml(), '1');
}
