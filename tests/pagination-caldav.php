<?php

declare(strict_types=1);

use AiphraCore\Exceptions\UnsupportedOperationException;
use AiphraCore\Filters\{Field, Operator};
use AiphraCore\Method;
use AiphraCore\Operation;
use AiphraCore\Providers\CalDav\Provider;

$tests = [];

$tests['CalDAV limit zero skips even discovery requests'] = static function (): void {
    $http = new FakeHttp();
    same(0, count((new Provider('https://dav.test/', $http))->calendars(Operation::Get)->limit(0)->exec()));
    same([], $http->requests);
};

$tests['CalDAV stops before requesting the next calendar folder'] = static function (): void {
    $http = new FakeHttp();
    $http->expect('PROPFIND', 'https://dav.test/', multi(entry('/',
        '<c:calendar-home-set><d:href>/home/</d:href><d:href>/shared/</d:href></c:calendar-home-set>')), '0')
        ->expect('PROPFIND', 'https://dav.test/home/', calendarsXml(), '1');
    $result = (new Provider('https://dav.test/', $http))->calendars(Operation::Get)->offset(1)->limit(1)->exec();
    same('https://dav.test/home/personal/', $result->all()[0]->getId());
    same(2, count($http->requests));
    $http->done();
};

$tests['CalDAV filters and removes duplicates before counting offset across folders'] = static function (): void {
    $http = new FakeHttp();
    $calendar = static fn (string $url, string $name): string => entry($url,
        '<d:resourcetype><c:calendar/></d:resourcetype><d:displayname>' . $name . '</d:displayname>');
    $http->expect('PROPFIND', 'https://dav.test/', multi(entry('/',
        '<c:calendar-home-set><d:href>/home/</d:href><d:href>/shared/</d:href><d:href>/unused/</d:href></c:calendar-home-set>')), '0')
        ->expect('PROPFIND', 'https://dav.test/home/', multi($calendar('/other/', 'Other') . $calendar('/one/', 'Wanted A')), '1')
        ->expect('PROPFIND', 'https://dav.test/shared/', multi($calendar('/one/', 'Wanted A') . $calendar('/two/', 'Wanted B')), '1');
    $result = (new Provider('https://dav.test/', $http))->calendars(Operation::Get)
        ->addFilter(Field::genFilter('name', Operator::Contains, ['Wanted']))->offset(1)->limit(1)->exec();
    same('https://dav.test/two/', $result->all()[0]->getId());
    same(3, count($http->requests));
    $http->done();
};

$tests['CalDAV offsets beyond the available calendars finish without extra requests'] = static function (): void {
    $http = new FakeHttp();
    discovery($http);
    same(0, count((new Provider('https://dav.test/', $http))->calendars(Operation::Get)->offset(10)->limit(2)->exec()));
    $http->done();
};

$tests['a CalDAV-only provider does not silently use CalDAV for an API override'] = static function (): void {
    $http = new FakeHttp();
    $query = (new Provider('https://dav.test/', $http))->calendars(Operation::Get)->setMethod(Method::Api);
    raises(UnsupportedOperationException::class, fn () => $query->exec());
    same([], $http->requests);
};

return $tests;
