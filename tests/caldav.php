<?php

declare(strict_types=1);

use AiphraCore\CalDav\Client as CalDavClient;
use AiphraCore\Exceptions\{AuthorizationException, InvalidConfigurationException, ProviderResponseException, ResourceNotFoundException, UnsupportedOperationException, ValidationException};
use AiphraCore\Filters\{Field, Operator};
use AiphraCore\Http\{HttpClient, Response};
use AiphraCore\Operation;
use AiphraCore\Method;
use AiphraCore\Providers\AbstractProvider;
use AiphraCore\Providers\CalDav\Provider;
use AiphraCore\Providers\GoogleCalendar;
use AiphraCore\Providers\Traits\GetCalendars;

$tests = [];

$tests['addFilter validates DTO field names and single-calendar identifiers immediately'] = static function (): void {
    $http = new FakeHttp();
    $provider = new Provider('https://dav.test/', $http);
    raises(ValidationException::class, fn () => $provider->addFilter(eq('name', 'Work')));
    raises(ValidationException::class, fn () => $provider->calendars(Operation::Get)->addFilter(eq('title', 'Work')));
    raises(ValidationException::class, fn () => $provider->calendar(Operation::Get)->addFilter(Field::genFilter('id', Operator::Contains, ['work'])));
    raises(ValidationException::class, fn () => $provider->calendar(Operation::Get)->addFilter(eq('id', '')));
    raises(ValidationException::class, fn () => $provider->calendar(Operation::Get)->addFilter(eq('id', 'one'))->addFilter(eq('id', 'two')));
    raises(ValidationException::class, fn () => Field::genFilter('name', Operator::Equals, ['one', 'two']));
    raises(ValidationException::class, fn () => Field::genFilter('name', Operator::Equals, [123]));
    same([], $http->requests);
};

$tests['exec checks registered operations before HTTP'] = static function (): void {
    $http = new FakeHttp();
    $client = new CalDavClient($http, 'https://dav.test/');
    $unsupported = new class extends AbstractProvider {};
    raises(UnsupportedOperationException::class, fn () => $unsupported->calendars(Operation::Get)->exec());
    $listOnly = new class($client) extends AbstractProvider {
        use GetCalendars;
        public function __construct(protected readonly CalDavClient $caldav) {}
        protected function operations(Method $method): array {
            return $method === Method::CalDav ? ['getCalendars' => $this->getCalendars(...)] : [];
        }
    };
    raises(UnsupportedOperationException::class, fn () => $listOnly->calendar(Operation::Get)->addFilter(eq('id', 'https://dav.test/home/work/'))->exec());
    raises(UnsupportedOperationException::class, fn () => (new Provider('https://dav.test/', $http))->calendars(Operation::Add)->exec());
    same([], $http->requests);
    discovery($http);
    same(2, count($listOnly->calendars(Operation::Get)->exec()));
    $http->done();
};

$tests['list uses standard CalDAV discovery and filters normalized DTOs'] = static function (): void {
    $http = new FakeHttp();
    discovery($http);
    $query = (new Provider('https://dav.test/', $http))->calendars(Operation::Get)
        ->addFilter(Field::genFilter('name', Operator::Contains, ['Работа']))
        ->addFilter(Field::genFilter('name', Operator::In, ['Работа & встречи', 'Other']));
    same([], $http->requests);
    $calendars = $query->exec();
    same(1, count($calendars));
    same('Работа & встречи', $calendars->all()[0]->getName());
    same('https://dav.test/home/work/', $calendars->all()[0]->getId());
    $http->done();
};

$tests['calendar by ID makes one Depth 0 request and returns a Calendar'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('PROPFIND', 'https://dav.test/home/work/', multi(entry('/home/work/', '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Work</d:displayname>')), '0');
    $calendar = (new Provider('https://dav.test/', $http))->calendar(Operation::Get)
        ->addFilter(eq('id', 'https://dav.test/home/work/'))->addFilter(eq('name', 'Work'))->exec();
    same('Work', $calendar->getName());
    same('https://dav.test/home/work/', $calendar->getId());
    $http->done();
};

$tests['single calendar requires ID before HTTP and reports missing resources'] = static function (): void {
    $http = new FakeHttp();
    $provider = new Provider('https://dav.test/', $http);
    raises(ValidationException::class, fn () => $provider->calendar(Operation::Get)->exec());
    raises(ValidationException::class, fn () => $provider->exec());
    same([], $http->requests);
    $http->expect('PROPFIND', 'https://dav.test/missing/', new Response(404), '0');
    raises(ResourceNotFoundException::class, fn () => $provider->calendar(Operation::Get)->addFilter(eq('id', 'https://dav.test/missing/'))->exec());
    $http->done();
};

$tests['each fluent operation starts with independent empty filters'] = static function (): void {
    $http = new FakeHttp();
    $provider = new Provider('https://dav.test/', $http);
    $work = $provider->calendars(Operation::Get)->addFilter(eq('name', 'Работа & встречи'));
    $personal = $provider->calendars(Operation::Get)->addFilter(eq('name', 'Личное'));
    discovery($http);
    discovery($http);
    same('Работа & встречи', $work->exec()->all()[0]->getName());
    same('Личное', $personal->exec()->all()[0]->getName());
    $http->done();
};

$tests['principal URLs, multiple home sets and relative hrefs use the same client'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('PROPFIND', 'https://dav.test/users/me/', multi(entry('/users/me/', '<c:calendar-home-set><d:href>../../home/</d:href><d:href>/shared/</d:href></c:calendar-home-set>')), '0')
        ->expect('PROPFIND', 'https://dav.test/home/', multi(entry('work/', '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Work</d:displayname>')), '1')
        ->expect('PROPFIND', 'https://dav.test/shared/', multi(entry('/home/work/', '<d:resourcetype><d:collection/><c:calendar/></d:resourcetype><d:displayname>Work</d:displayname>')), '1');
    $calendars = (new Provider('https://dav.test/users/me/', $http))->calendars(Operation::Get)->exec();
    same(1, count($calendars));
    same('https://dav.test/home/work/', $calendars->all()[0]->getId());
    $http->done();
};

$tests['invalid XML and resource errors cannot produce partial successful lists'] = static function (): void {
    foreach ([new Response(207, ''), new Response(207, '<broken>'), new Response(200, '<html/>')] as $response) {
        $http = new FakeHttp();
        $http->expect('PROPFIND', 'https://dav.test/', $response);
        raises(ProviderResponseException::class, fn () => (new Provider('https://dav.test/', $http))->calendars(Operation::Get)->exec());
    }
    foreach ([403 => AuthorizationException::class, 404 => ResourceNotFoundException::class] as $status => $exception) {
        $http = new FakeHttp();
        $http->expect('PROPFIND', 'https://dav.test/home/work/', multi('<d:response><d:href>/home/work/</d:href><d:status>HTTP/1.1 ' . $status . ' Error</d:status></d:response>'));
        raises($exception, fn () => (new Provider('https://dav.test/', $http))->calendar(Operation::Get)->addFilter(eq('id', 'https://dav.test/home/work/'))->exec());
        $http->done();
    }
};

$tests['CalDAV requests specify the required properties and depth'] = static function (): void {
    $checkProperties = static function (string $xml, array $expected): void {
        $document = new DOMDocument();
        same(true, $document->loadXML($xml));
        $properties = [];
        foreach ($document->documentElement->firstChild->childNodes as $property) {
            if ($property instanceof DOMElement) {
                $properties[] = [$property->namespaceURI, $property->localName];
            }
        }
        same($expected, $properties);
    };
    $calendarProperties = [['DAV:', 'resourcetype'], ['DAV:', 'displayname']];
    $http = new FakeHttp();
    $http->expect('PROPFIND', 'https://dav.test/', static function ($headers, $body) use ($checkProperties): Response {
        $checkProperties($body, [['DAV:', 'current-user-principal'], ['urn:ietf:params:xml:ns:caldav', 'calendar-home-set']]);
        return multi(entry('/', '<c:calendar-home-set><d:href>/home/</d:href></c:calendar-home-set>'));
    }, '0')->expect('PROPFIND', 'https://dav.test/home/', static function ($headers, $body) use ($checkProperties, $calendarProperties): Response {
        $checkProperties($body, $calendarProperties);
        return calendarsXml();
    }, '1')->expect('PROPFIND', 'https://dav.test/home/work/', static function ($headers, $body) use ($checkProperties, $calendarProperties): Response {
        $checkProperties($body, $calendarProperties);
        return multi(entry('/home/work/', '<d:resourcetype><c:calendar/></d:resourcetype><d:displayname>Work</d:displayname>'));
    }, '0');
    $provider = new Provider('https://dav.test/', $http);
    same(2, count($provider->calendars(Operation::Get)->exec()));
    same('Work', $provider->calendar(Operation::Get)->addFilter(eq('id', 'https://dav.test/home/work/'))->exec()->getName());
    $http->done();
};

$tests['calendar URLs and discovery remain on the configured HTTP origin'] = static function (): void {
    foreach (['https://other.test/calendar/', '//other.test/calendar/', 'ftp://dav.test/calendar/', 'https://user:password@dav.test/calendar/', 'https://dav.test/calendar/#fragment'] as $url) {
        $http = new FakeHttp();
        $provider = new Provider('https://dav.test/', $http);
        raises(ProviderResponseException::class, fn () => $provider->calendar(Operation::Get)->addFilter(eq('id', $url))->exec());
        same([], $http->requests);

        $http->expect('PROPFIND', 'https://dav.test/', multi(entry('/', '<c:calendar-home-set><d:href>' . xmlText($url) . '</d:href></c:calendar-home-set>')), '0');
        raises(ProviderResponseException::class, fn () => $provider->calendars(Operation::Get)->exec());
        same(1, count($http->requests));
        $http->done();
    }
    foreach (['', '/relative/', 'ftp://dav.test/', 'https://user@dav.test/', 'https://dav.test/#fragment'] as $url) {
        raises(InvalidConfigurationException::class, fn () => new Provider($url, new FakeHttp()));
    }
};

$tests['XML namespace prefixes and missing optional properties do not change calendars'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('PROPFIND', 'https://dav.test/home/work/', new Response(207,
        '<multistatus xmlns="DAV:" xmlns:calendar="urn:ietf:params:xml:ns:caldav">'
        . '<response><href>/home/work/</href><propstat><prop><resourcetype><calendar:calendar/></resourcetype></prop>'
        . '<status>HTTP/1.1 200 OK</status></propstat><propstat><prop><displayname>Ignore me</displayname></prop>'
        . '<status>HTTP/1.1 404 Not Found</status></propstat></response></multistatus>'), '0');
    $calendar = (new Provider('https://dav.test/', $http))->calendar(Operation::Get)->addFilter(eq('id', 'https://dav.test/home/work/'))->exec();
    same('https://dav.test/home/work/', $calendar->getId());
    same('', $calendar->getName());
    $http->done();
};

$tests['XML with a doctype, a wrong namespace or a missing href is rejected'] = static function (): void {
    foreach ([
        '<!DOCTYPE d:multistatus [<!ENTITY label "test">]><d:multistatus xmlns:d="DAV:"/>',
        '<multistatus xmlns="urn:wrong"/>',
        '<d:multistatus xmlns:d="DAV:"><d:response><d:propstat><d:prop/><d:status>HTTP/1.1 200 OK</d:status></d:propstat></d:response></d:multistatus>',
    ] as $xml) {
        $http = new FakeHttp();
        $http->expect('PROPFIND', 'https://dav.test/', new Response(207, $xml), '0');
        raises(ProviderResponseException::class, fn () => (new Provider('https://dav.test/', $http))->calendars(Operation::Get)->exec());
        $http->done();
    }
};

$tests['relative calendar URLs normalize paths and preserve query parameters'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('PROPFIND', 'https://dav.test/home/work/?view=full', multi(entry('./?view=full',
        '<d:resourcetype><c:calendar/></d:resourcetype><d:displayname>Work</d:displayname>')), '0');
    $calendar = (new CalDavClient($http, 'https://dav.test/home/'))->calendar('./personal/../work/?view=full');
    same('https://dav.test/home/work/?view=full', $calendar->getId());
    $http->done();
};

return $tests;
